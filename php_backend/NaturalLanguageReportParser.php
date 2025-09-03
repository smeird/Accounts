<?php
// Parses plain-English report requests into Transaction::filter parameters.
require_once __DIR__ . '/Database.php';

require_once __DIR__ . '/models/Setting.php';

class NaturalLanguageReportParser {
    /**
     * Convert a free-text query into filters for Transaction::filter().

     * Tries the AI integration when an API token is configured and falls
     * back to a simple rule-based parser otherwise.
     */
    public static function parse(string $query): array {
        $token = Setting::get('openai_api_token');
        if ($token) {
            $ai = self::parseWithAI($query, $token);
            if ($ai !== null) {
                return $ai;
            }
        }
        return self::parseFallback($query);
    }

    /**
     * Use the OpenAI chat API to interpret the query.
     * Returns null if the API request fails or the response is invalid.
     */
    private static function parseWithAI(string $query, string $token): ?array {
        $db = Database::getConnection();

        $tables = [
            'categories' => [],
            'tags' => [],
            'segments' => [],
            'transaction_groups' => [],
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

        $prompt = "Convert the following query into JSON {\"category\",\"tag\",\"segment\",\"group\",\"start\",\"end\",\"text\"}. " .
            "Return tag as an array of tag names (use an empty array when none). " .
            "Use ISO dates and only the names listed.\n\n" .
            "Categories:\n- " . implode("\n- ", $names['categories']) . "\n\n" .
            "Tags:\n- " . implode("\n- ", $names['tags']) . "\n\n" .
            "Segments:\n- " . implode("\n- ", $names['segments']) . "\n\n" .
            "Groups:\n- " . implode("\n- ", $names['transaction_groups']) . "\n\n" .
            "Query: $query";

        $payload = [
            'model' => 'gpt-5-nano',
            'messages' => [
                ['role' => 'system', 'content' => 'You convert report requests into JSON filters.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0,
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false || $code !== 200) {
            return null;
        }
        $data = json_decode($response, true);
        $content = $data['choices'][0]['message']['content'] ?? '';

        $content = trim($content);
        if (substr($content, 0, 3) === '```') {
            $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
            $content = preg_replace('/```\s*$/', '', $content);
            $content = trim($content);
        }

        $parsed = json_decode($content, true);
        if (!is_array($parsed)) {
            return null;
        }

        $filters = [
            'category' => null,
            'tag' => [],
            'group' => null,
            'segment' => null,
            'start' => $parsed['start'] ?? null,
            'end' => $parsed['end'] ?? null,
            'text' => $parsed['text'] ?? null,
        ];

        // Map single-name fields
        foreach ([
            'category' => 'categories',
            'group' => 'transaction_groups',
            'segment' => 'segments',
        ] as $field => $table) {
            $name = $parsed[$field] ?? null;
            if ($name) {
                $id = $tables[$table][strtolower($name)] ?? null;
                if ($id !== null) {
                    $filters[$field] = $id;
                }
            }
        }

        // Map tag array
        $tagNames = $parsed['tag'] ?? [];
        if (!is_array($tagNames)) {
            $tagNames = [$tagNames];
        }
        foreach ($tagNames as $name) {
            $id = $tables['tags'][strtolower($name)] ?? null;
            if ($id !== null) {
                $filters['tag'][] = $id;
            }
        }
        if (empty($filters['tag'])) {
            $filters['tag'] = null;
        }

        return $filters;
    }

    /**
     * Simple regex-based fallback parser used when AI is unavailable.
     */
    private static function parseFallback(string $query): array {

        $filters = [
            'category' => null,
            'tag' => null,
            'group' => null,
            'segment' => null,
            'start' => null,
            'end' => null,
            'text' => null,
        ];

        $q = strtolower($query);
        $filters['category'] = self::matchName($q, 'categories');
        $tags = self::matchNames($q, 'tags');
        $filters['tag'] = $tags ? $tags : null;
        $filters['group'] = self::matchName($q, 'transaction_groups');
        $filters['segment'] = self::matchName($q, 'segments');


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

        return $filters;
    }

    /**
     * Find the id of an entity in a table whose name appears in the query.
     */
    private static function matchName(string $query, string $table): ?int {
        $db = Database::getConnection();
        $stmt = $db->query("SELECT id, name FROM $table");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $pattern = '/\\b' . preg_quote($row['name'], '/') . '\\b/i';
            if (preg_match($pattern, $query)) {
                return (int)$row['id'];
            }
        }
        return null;
    }

    /**
     * Find ids of all entities in a table whose name appears in the query.
     */
    private static function matchNames(string $query, string $table): array {
        $db = Database::getConnection();
        $stmt = $db->query("SELECT id, name FROM $table");
        $ids = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $pattern = '/\\b' . preg_quote($row['name'], '/') . '\\b/i';
            if (preg_match($pattern, $query)) {
                $ids[] = (int)$row['id'];
            }
        }
        return $ids;
    }
}
?>
