<?php
// Use OpenAI to suggest tags and categories for untagged transactions.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../models/Tag.php';
require_once __DIR__ . '/../models/TagAlias.php';
require_once __DIR__ . '/../models/CategoryTag.php';
require_once __DIR__ . '/../models/Setting.php';
require_once __DIR__ . '/../models/Log.php';
require_once __DIR__ . '/../AiTaggingPipeline.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$apiKey = Setting::get('openai_api_token');
if (!$apiKey) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing API token']);
    exit;
}

$db = Database::getConnection();
// Identify the most common untagged transactions by description and memo
$limit = (int)(Setting::get('ai_tag_batch_size') ?? 100);
if ($limit <= 0) $limit = 100;
$txns = $db->query('SELECT MIN(id) AS id, description, memo, ROUND(AVG(amount),2) AS amount, COUNT(*) AS cnt FROM transactions WHERE tag_id IS NULL GROUP BY description, memo ORDER BY cnt DESC LIMIT ' . $limit)->fetchAll(PDO::FETCH_ASSOC);
if (!$txns) {
    echo json_encode(['processed' => 0, 'tokens' => 0]);
    exit;
}
$categories = $db->query('SELECT id, name FROM categories')->fetchAll(PDO::FETCH_ASSOC);
$tagContextRows = $db->query('SELECT t.id AS tag_id, t.name AS tag_name, ta.alias FROM tags t LEFT JOIN tag_aliases ta ON ta.tag_id = t.id AND ta.active = 1 ORDER BY t.name ASC, ta.id ASC')->fetchAll(PDO::FETCH_ASSOC);
$tagContext = AiTaggingPipeline::buildAliasAwareTagContext($tagContextRows, 5, 2500);

$txnMap = [];
$aliasResolutions = [];
$learnedAliases = [];

/**
 * Build a conservative descriptor string from transaction fields for alias learning.
 */
function buildAliasDescriptor(array $txn): string {
    $parts = [];
    if (!empty($txn['description'])) {
        $parts[] = trim((string)$txn['description']);
    }
    if (!empty($txn['memo'])) {
        $parts[] = trim((string)$txn['memo']);
    }
    return trim(implode(' ', array_filter($parts, function ($part) {
        return $part !== '';
    })));
}

/**
 * Exclude low-signal alias candidates.
 */
function isValidLearnedAlias(string $alias): bool {
    $alias = trim($alias);
    if ($alias === '') {
        return false;
    }
    if (strlen($alias) < 4 || strlen($alias) > 150) {
        return false;
    }
    if (preg_match('/^\d+(?:[\s\-\._]?\d+)*$/', $alias)) {
        return false;
    }
    if (preg_match('/^[\W_]+$/u', $alias)) {
        return false;
    }

    $genericWords = [
        'payment', 'purchase', 'transfer', 'transaction', 'card', 'debit', 'credit',
        'cash', 'online', 'bank', 'account', 'pending', 'charge', 'refund'
    ];
    $normalized = TagAlias::normalizeAlias($alias);
    if (in_array($normalized, $genericWords, true)) {
        return false;
    }

    return true;
}

/**
 * Persist alias safely, handling duplicate-key conflicts gracefully.
 */
function createLearnedAlias(PDO $db, int $tagId, string $alias): array {
    $alias = trim($alias);
    $normalized = TagAlias::normalizeAlias($alias);
    $result = [
        'alias' => $alias,
        'alias_normalized' => $normalized,
        'tag_id' => $tagId,
        'status' => 'ignored',
        'reason' => null,
    ];

    if ($normalized === '') {
        $result['reason'] = 'empty_normalized';
        return $result;
    }

    try {
        TagAlias::create($tagId, $alias, 'contains', true);
        $result['status'] = 'created';
        return $result;
    } catch (PDOException $e) {
        // 23000 is SQLSTATE integrity constraint violation (e.g., duplicate key).
        if (($e->getCode() === '23000' || $e->getCode() === 23000) && stripos($e->getMessage(), 'duplicate') !== false) {
            $upd = $db->prepare('UPDATE tag_aliases SET tag_id = :tag_id, alias = :alias, match_type = :match_type, active = 1 WHERE alias_normalized = :alias_normalized');
            $upd->execute([
                'tag_id' => $tagId,
                'alias' => $alias,
                'match_type' => 'contains',
                'alias_normalized' => $normalized,
            ]);
            $result['status'] = $upd->rowCount() > 0 ? 'updated' : 'unchanged';
            return $result;
        }
        $result['status'] = 'error';
        $result['reason'] = $e->getMessage();
        return $result;
    }
}

$prompt = "You are a financial assistant. For each transaction provide a short canonical tag and an optional brief description for that tag. If the transaction details are ambiguous, use a generic canonical tag name. ";
$prompt .= "Aliases are examples that map to canonical tags. Always return the canonical tag name in the tag field, never an alias literal. ";
$prompt .= "Prioritise canonical tag selection accuracy over other metadata. Category is optional metadata and may be omitted. ";
$prompt .= "Return JSON only as a top-level array of objects {\"id\":<id>,\"tag\":\"tag name\",\"description\":\"tag description\",\"category\":\"optional category name\"} ";
$prompt .= "or as an object {\"transactions\":[...]} containing that array. Do not return a single object.\n\n";

if ($tagContext['text'] !== '') {
    $prompt .= "Canonical tags with alias examples (alias -> canonical):\n" . $tagContext['text'] . "\n\n";
}

$prompt .= "Categories:\n";
foreach ($categories as $c) {
    $prompt .= "- {$c['name']}\n";
}
$prompt .= "\nTransactions:\n";
foreach ($txns as $t) {
    $txnMap[$t['id']] = $t;
    $memo = $t['memo'] !== null && $t['memo'] !== '' ? " | {$t['memo']}" : '';
    $prompt .= "{$t['id']}: {$t['description']}{$memo} ({$t['amount']})\n";
}

$model = Setting::get('ai_model') ?? 'gpt-5-nano';
$temperature = Setting::get('ai_temperature');
if ($temperature === null || $temperature === '') {
    $temperature = 1;
}
$debugMode = Setting::get('ai_debug') === '1';
$payload = [
    'model' => $model,
    'input' => [
        ['role' => 'system', 'content' => 'You label bank transactions. Use JSON.'],
        ['role' => 'user', 'content' => $prompt]
    ],
    'temperature' => (float)$temperature,
    'text' => ['format' => ['type' => 'json_object']],
];

$ch = curl_init('https://api.openai.com/v1/responses');
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
]);
$response = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if ($response === false || $code !== 200) {
    http_response_code(500);
    Log::write('AI tag API error: ' . ($response ?: 'no response'), 'ERROR');
    echo json_encode(['error' => 'OpenAI request failed']);
    exit;
}
$data = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(500);
    Log::write('AI tag API JSON decode error: ' . json_last_error_msg() . ' | ' . $response, 'ERROR');
    echo json_encode(['error' => 'Invalid AI response']);
    exit;
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

$usage = $data['usage']['total_tokens'] ?? 0;
if ($content === '') {
    http_response_code(500);
    Log::write('AI tag empty response: ' . $response, 'ERROR');
    echo json_encode(['error' => 'Invalid AI response']);
    exit;
}


// Strip Markdown code fences if present
$content = trim($content);
if (substr($content, 0, 3) === '```') {
    $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
    $content = preg_replace('/```\s*$/', '', $content);
    $content = trim($content);
}


$suggestions = json_decode($content, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(500);
    Log::write('AI tag invalid JSON: ' . json_last_error_msg() . ' | ' . $content, 'ERROR');
    echo json_encode(['error' => 'Invalid AI response']);
    exit;
}

if (is_array($suggestions)) {
    if (isset($suggestions['transactions']) && is_array($suggestions['transactions'])) {
        $suggestions = $suggestions['transactions'];
    } elseif (isset($suggestions['id']) && isset($suggestions['tag'])) {
        $suggestions = [$suggestions];
    }

}
if (!is_array($suggestions)) {
    http_response_code(500);
    Log::write('AI tag invalid response: ' . $content, 'ERROR');
    echo json_encode(['error' => 'Invalid AI response']);
    exit;
}

$processed = 0;
foreach ($suggestions as $s) {
    $txId = $s['id'] ?? null;
    $tagName = $s['tag'] ?? null;
    $catName = $s['category'] ?? null;
    $tagDesc = $s['description'] ?? null;

    if (!$txId || !$tagName) continue;

    $txn = $txnMap[$txId] ?? null;
    if (!$txn) continue;
    $keyword = substr($txn['description'], 0, 100);

    $resolved = AiTaggingPipeline::resolveCanonicalTag((string)$tagName, $tagContext['canonicalByName'], $tagContext['aliasToCanonical']);
    $modelTagText = trim((string)$tagName);
    if ($resolved !== null) {
        $tagId = (int)$resolved['id'];
        $canonicalTagName = $resolved['name'];
        $canonicalDiffers = strcasecmp($modelTagText, (string)$canonicalTagName) !== 0;
        if ($resolved['source'] === 'alias') {
            $aliasResolutions[] = ['input' => $tagName, 'canonical' => $canonicalTagName, 'id' => $tagId];
        }
        $tagName = $canonicalTagName;
        Tag::setKeywordIfMissing($tagId, $keyword);
        if ($tagDesc) {
            Tag::setDescriptionIfMissing($tagId, $tagDesc);
        }

        if ($resolved['source'] === 'alias' || $canonicalDiffers) {
            $aliasCandidate = buildAliasDescriptor($txn);
            if (isValidLearnedAlias($aliasCandidate)) {
                $learned = createLearnedAlias($db, (int)$tagId, $aliasCandidate);
                $learned['tx_id'] = (int)$txId;
                $learned['canonical'] = $canonicalTagName;
                $learned['trigger'] = $resolved['source'] === 'alias' ? 'resolved_from_alias' : 'mapped_to_canonical';
                $learnedAliases[] = $learned;
                if ($learned['status'] === 'created' || $learned['status'] === 'updated') {
                    Log::write("AI learned tag alias '{$learned['alias']}' for canonical tag '{$canonicalTagName}' (tag_id={$tagId}, trigger={$learned['trigger']}, status={$learned['status']})");
                }
            } else {
                $learnedAliases[] = [
                    'tx_id' => (int)$txId,
                    'canonical' => $canonicalTagName,
                    'alias' => $aliasCandidate,
                    'status' => 'filtered',
                    'trigger' => $resolved['source'] === 'alias' ? 'resolved_from_alias' : 'mapped_to_canonical',
                ];
            }
        }
    } else {
        $tagId = Tag::getIdByName($tagName);
        if ($tagId === null) {
            $tagId = Tag::create($tagName, $keyword, $tagDesc);
        } else {
            // getIdByName performs normalized lookup to prevent duplicate tags.
            Tag::setKeywordIfMissing($tagId, $keyword);
            if ($tagDesc) {
                Tag::setDescriptionIfMissing($tagId, $tagDesc);
            }
        }
    }

    $catId = CategoryTag::getCategoryId((int)$tagId);
    if ($catId === null && $catName) {
        $stmt = $db->prepare('SELECT id FROM categories WHERE name = :name LIMIT 1');
        $stmt->execute(['name' => $catName]);
        $fallbackCatId = $stmt->fetchColumn();
        if ($fallbackCatId !== false) {
            try {
                CategoryTag::add((int)$fallbackCatId, (int)$tagId);
            } catch (Exception $e) {
                // Tag may already be assigned; ignore
            }
            $catId = CategoryTag::getCategoryId((int)$tagId);
        }
    }

    if ($catId !== null) {
        $upd = $db->prepare('UPDATE transactions SET tag_id = :tag, category_id = :cat WHERE description = :desc AND memo <=> :memo AND tag_id IS NULL');
        $upd->execute(['tag' => $tagId, 'cat' => (int)$catId, 'desc' => $txn['description'], 'memo' => $txn['memo']]);
    } else {
        $upd = $db->prepare('UPDATE transactions SET tag_id = :tag WHERE description = :desc AND memo <=> :memo AND tag_id IS NULL');
        $upd->execute(['tag' => $tagId, 'desc' => $txn['description'], 'memo' => $txn['memo']]);
    }
    $processed += $upd->rowCount();
}

Log::write("AI tagged $processed transactions using $usage tokens");
if (!empty($learnedAliases)) {
    Log::write('AI alias learning summary: ' . json_encode($learnedAliases));
}
 $out = ['processed' => $processed, 'tokens' => $usage];
 if ($debugMode) {
     $out['debug'] = [
         'prompt' => $prompt,
         'response' => $content,
         'alias_context' => $tagContext['text'],
         'alias_context_truncated' => $tagContext['truncated'],
         'alias_resolutions' => $aliasResolutions,
         'learned_aliases' => $learnedAliases,
     ];
 }
 echo json_encode($out);
// Self-check:
// Endpoint detected: Responses
// Using text.format.type = json_object for structured JSON tag suggestions
?>
