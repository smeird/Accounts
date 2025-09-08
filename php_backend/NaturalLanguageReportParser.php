<?php
// Parses plain-English report requests into Transaction::filter parameters.
require_once __DIR__ . '/Database.php';

require_once __DIR__ . '/models/Setting.php';
require_once __DIR__ . '/models/Log.php';

class NaturalLanguageReportParser {
    /**
     * Convert a free-text query into filters for Transaction::filter().

     * Tries the AI integration when an API token is configured and falls
     * back to a simple rule-based parser otherwise.
     */
    public static function parse(string $query): array {
        $token = Setting::get('openai_api_token');
        if ($token) {
            Log::write('NL report token present, attempting AI parse');
            $ai = self::parseWithAI($query, $token);
            if ($ai !== null) {
                return $ai;
            }
            Log::write('NL report AI parse failed; using fallback');
        } else {
            Log::write('NL report no API token; using fallback');
        }
        return self::parseFallback($query);
    }

    /**
     * Use the OpenAI Responses API to interpret the query.
     * Returns null if the API request fails or the response is invalid.
     */
    private static function parseWithAI(string $query, string $token): ?array {
        Log::write('NL report AI query: ' . $query);
        $db = Database::getConnection();

        // Only tags are needed for AI parsing. Other fields are deliberately
        // omitted to avoid over-constrained searches.
        $tables = [
            'tags' => [],
        ];
        $names = [];
        foreach ($tables as $table => $_) {
            $rows = $db->query("SELECT id, name FROM $table")->fetchAll(PDO::FETCH_ASSOC);
            $map = [];
            $list = [];
            foreach ($rows as $r) {
                $map[strtolower($r['name'])] = (int)$r['id'];
                $list[] = $r['name'];
            }
            $tables[$table] = $map;
            $names[$table] = $list;
        }

        $prompt = "Convert the following query into JSON {\"tag\",\"start\",\"end\",\"name\",\"description\",\"summary\"}. " .
            "Return tag as an array of tag names (use an empty array when none). " .
            "Include a short search name and a concise description. " .
            "The summary should be a short natural language description of the filters. " .
            "Use ISO dates and only the tag names listed.\n\n" .
            "Tags:\n- " . implode("\n- ", $names['tags']) . "\n\n" .
            "Query: $query";

        $model = Setting::get('ai_model') ?? 'gpt-5-nano';
        $temperature = Setting::get('ai_temperature');
        if ($temperature === null || $temperature === '') {
            $temperature = 0;
        }
        $debugMode = Setting::get('ai_debug') === '1';
        $payload = [
            'model' => $model,
            'input' => [
                ['role' => 'system', 'content' => 'You convert report requests into JSON filters.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => (float)$temperature,
            'text' => ['format' => ['type' => 'json_object']],
        ];

        $ch = curl_init('https://api.openai.com/v1/responses');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            Log::write('NL report AI HTTP error: ' . curl_error($ch), 'ERROR');
            return null;
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        Log::write('NL report AI HTTP status: ' . $code);
        if ($code !== 200) {
            Log::write('NL report AI bad response: ' . $response, 'ERROR');
            return null;
        }
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::write('NL report AI JSON decode failed: ' . json_last_error_msg() . ' | ' . $response, 'ERROR');
            return null;
        }
        $content = $data['output_text'] ?? '';
        if ($content === '' && isset($data['output']) && is_array($data['output'])) {
            foreach ($data['output'] as $out) {
                if (!empty($out['content'][0]['text'])) {
                    $content = $out['content'][0]['text'];
                    break;
                }
            }
        }
        if ($content === '' && isset($data['choices'][0]['message']['content'])) {
            $content = $data['choices'][0]['message']['content'];
        }
        Log::write('NL report AI raw content: ' . $content);
        if ($content === '') {
            Log::write('NL report AI empty response', 'ERROR');
            return null;
        }

        $content = trim($content);
        if (substr($content, 0, 3) === '```') {
            $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
            $content = preg_replace('/```\s*$/', '', $content);
            $content = trim($content);
        }

        $parsed = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($parsed)) {
            Log::write('NL report AI content decode failed: ' . $content, 'ERROR');
            return null;
        }

        $filters = [
            'category' => null,
            'tag' => [],
            'group' => null,
            'segment' => null,
            'start' => $parsed['start'] ?? null,
            'end' => $parsed['end'] ?? null,
            'text' => null,
            'memo' => null,
            'summary' => $parsed['summary'] ?? null,
            'name' => $parsed['name'] ?? null,
            'description' => $parsed['description'] ?? null,
        ];

        // Map tag array
        $tagNames = $parsed['tag'] ?? [];
        if (!is_array($tagNames)) {
            $tagNames = [$tagNames];
        }
        foreach ($tagNames as $name) {
            $id = $tables['tags'][strtolower($name)] ?? null;
            if ($id !== null) {
                $filters['tag'][] = $id;
            } else {
                Log::write('NL report AI unknown tag: ' . $name, 'ERROR');
            }
        }
        if (empty($filters['tag'])) {
            $filters['tag'] = null;
        }

        if (empty($filters['summary'])) {
            $filters['summary'] = self::makeSummary($filters);
        }

        Log::write('NL report AI filters: ' . json_encode($filters));
        if ($debugMode) {
            $filters['debug'] = ['prompt' => $prompt, 'response' => $content];
        }
        return $filters;
    }

    /**
     * Simple regex-based fallback parser used when AI is unavailable.
     */
    private static function parseFallback(string $query): array {

        Log::write('NL report fallback parser used for query: ' . $query);

        $filters = [
            'category' => null,
            'tag' => null,
            'group' => null,
            'segment' => null,
            'start' => null,
            'end' => null,
            'text' => null,
            'memo' => null,
            'summary' => null,
            'name' => null,
            'description' => null,
        ];

        $q = strtolower($query);
        $tags = self::matchNames($q, 'tags');
        $filters['tag'] = $tags ? $tags : null;

        if (preg_match('/last\s+(\d+)\s+months?/', $q, $m)) {
            $months = (int)$m[1];
            $filters['start'] = date('Y-m-d', strtotime("-$months months"));
            $filters['end'] = date('Y-m-d');
        } elseif (preg_match('/last\s+(\d+)\s+years?/', $q, $m)) {
            $years = (int)$m[1];
            $filters['start'] = date('Y-m-d', strtotime("-$years years"));
            $filters['end'] = date('Y-m-d');
        } elseif (strpos($q, 'last year') !== false) {
            $filters['start'] = date('Y-m-d', strtotime('-1 year'));
            $filters['end'] = date('Y-m-d');
        } elseif (strpos($q, 'last month') !== false) {
            $filters['start'] = date('Y-m-d', strtotime('-1 month'));
            $filters['end'] = date('Y-m-d');
        }

        $filters['summary'] = self::makeSummary($filters);

        Log::write('NL report fallback filters: ' . json_encode($filters));

        return $filters;
    }

    /**
     * Find ids of all entities in a table whose name appears in the query.
     */
    private static function matchNames(string $query, string $table): array {
        $db = Database::getConnection();
        $stmt = $db->query("SELECT id, name FROM $table");
        $ids = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $pattern = '/(?<!\\w)' . preg_quote($row['name'], '/') . '(?!\\w)/i';
            if (preg_match($pattern, $query)) {
                $ids[] = (int)$row['id'];
            }
        }
        return $ids;
    }

    /**
     * Build a simple natural language summary of the applied filters.
     */
    private static function makeSummary(array $filters): string {
        $db = Database::getConnection();
        $parts = [];
        if ($filters['category']) {
            $stmt = $db->prepare('SELECT name FROM categories WHERE id = ?');
            $stmt->execute([$filters['category']]);
            if ($name = $stmt->fetchColumn()) {
                $parts[] = 'category ' . $name;
            }
        }
        if (is_array($filters['tag']) && $filters['tag']) {
            $in = implode(',', array_fill(0, count($filters['tag']), '?'));
            $stmt = $db->prepare("SELECT name FROM tags WHERE id IN ($in)");
            $stmt->execute($filters['tag']);
            $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if ($names) {
                $parts[] = 'tags ' . implode(', ', $names);
            }
        }
        if ($filters['group']) {
            $stmt = $db->prepare('SELECT name FROM transaction_groups WHERE id = ?');
            $stmt->execute([$filters['group']]);
            if ($name = $stmt->fetchColumn()) {
                $parts[] = 'group ' . $name;
            }
        }
        if ($filters['segment']) {
            $stmt = $db->prepare('SELECT name FROM segments WHERE id = ?');
            $stmt->execute([$filters['segment']]);
            if ($name = $stmt->fetchColumn()) {
                $parts[] = 'segment ' . $name;
            }
        }
        if (!empty($filters['text'])) {
            $parts[] = 'description contains "' . $filters['text'] . '"';
        }
        if (!empty($filters['memo'])) {
            $parts[] = 'memo contains "' . $filters['memo'] . '"';
        }
        if ($filters['start'] || $filters['end']) {
            $start = $filters['start'];
            $end = $filters['end'];
            if ($start && $end) {
                $parts[] = "from $start to $end";
            } elseif ($start) {
                $parts[] = "from $start onwards";
            } elseif ($end) {
                $parts[] = "up to $end";
            }
        }
        if (empty($parts)) {
            return 'No specific filters applied.';
        }
        return 'Report filtered by ' . implode(', ', $parts) . '.';
    }
}
// Self-check:
// Endpoint detected: Responses
// Using text.format.type = json_object for structured JSON filter parsing
?>
