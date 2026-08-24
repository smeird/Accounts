<?php
// Use OpenAI to suggest tags and categories for untagged transactions.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../models/Tag.php';
require_once __DIR__ . '/../models/TagAlias.php';
require_once __DIR__ . '/../models/CategoryTag.php';
require_once __DIR__ . '/../models/Segment.php';
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
// Apply learned local rules before spending an AI request on the same pattern.
$processedLocally = Tag::applyToAllTransactions();
if ($processedLocally > 0) {
    CategoryTag::applyToAllTransactions();
    Segment::applyToTransactions();
}
// Identify the most common untagged transactions by description and memo
$limit = (int)(Setting::get('ai_tag_batch_size') ?? 100);
if ($limit <= 0) $limit = 100;
$txns = $db->query('SELECT MIN(id) AS id, description, memo, ROUND(AVG(amount),2) AS amount, COUNT(*) AS cnt FROM transactions WHERE tag_id IS NULL AND transfer_id IS NULL GROUP BY description, memo ORDER BY cnt DESC LIMIT ' . $limit)->fetchAll(PDO::FETCH_ASSOC);
if (!$txns) {
    echo json_encode(['processed' => $processedLocally, 'tokens' => 0]);
    exit;
}
$categories = $db->query('SELECT id, name FROM categories')->fetchAll(PDO::FETCH_ASSOC);

/**
 * Normalize category names for robust matching.
 */
function normalizeCategoryName(string $name): string {
    $name = strtolower(trim($name));
    if ($name == '') {
        return '';
    }
    $name = preg_replace('/[[:punct:]]+/u', ' ', $name);
    $name = preg_replace('/\s+/u', ' ', $name);
    return trim($name);
}

/**
 * Build token list from normalized category name.
 */
function categoryTokens(string $normalizedName): array {
    if ($normalizedName === '') {
        return [];
    }
    return array_values(array_filter(explode(' ', $normalizedName), function ($token) {
        return $token !== '';
    }));
}

/**
 * Resolve category with strict token similarity fallback.
 */
function resolveCategoryId(string $categoryName, array $normalizedCategoryMap, array $categoryTokenMap, float $strictThreshold = 0.92): array {
    $normalized = normalizeCategoryName($categoryName);
    if ($normalized === '') {
        return ['id' => null, 'normalized' => $normalized, 'closest' => null, 'score' => 0.0, 'matched' => false, 'method' => null];
    }

    if (isset($normalizedCategoryMap[$normalized])) {
        return ['id' => (int)$normalizedCategoryMap[$normalized], 'normalized' => $normalized, 'closest' => $normalized, 'score' => 1.0, 'matched' => true, 'method' => 'normalized_exact'];
    }

    $inputTokens = categoryTokens($normalized);
    if (empty($inputTokens)) {
        return ['id' => null, 'normalized' => $normalized, 'closest' => null, 'score' => 0.0, 'matched' => false, 'method' => null];
    }

    $inputSet = array_fill_keys($inputTokens, true);
    $best = ['id' => null, 'normalized' => null, 'score' => 0.0];

    foreach ($categoryTokenMap as $candidate) {
        $candidateTokens = $candidate['tokens'];
        if (empty($candidateTokens)) {
            continue;
        }
        $candidateSet = array_fill_keys($candidateTokens, true);
        $intersection = count(array_intersect_key($inputSet, $candidateSet));
        $union = count($inputSet + $candidateSet);
        if ($union === 0) {
            continue;
        }
        $score = $intersection / $union;
        if ($score > $best['score']) {
            $best = ['id' => (int)$candidate['id'], 'normalized' => $candidate['normalized'], 'score' => $score];
        }
    }

    if ($best['id'] !== null && $best['score'] >= $strictThreshold) {
        return ['id' => $best['id'], 'normalized' => $normalized, 'closest' => $best['normalized'], 'score' => $best['score'], 'matched' => true, 'method' => 'token_similarity'];
    }

    return ['id' => null, 'normalized' => $normalized, 'closest' => $best['normalized'], 'score' => $best['score'], 'matched' => false, 'method' => 'token_similarity'];
}

$normalizedCategoryMap = [];
$categoryTokenMap = [];
foreach ($categories as $category) {
    $normalizedName = normalizeCategoryName((string)($category['name'] ?? ''));
    if ($normalizedName === '') {
        continue;
    }
    $normalizedCategoryMap[$normalizedName] = (int)$category['id'];
    $categoryTokenMap[] = [
        'id' => (int)$category['id'],
        'normalized' => $normalizedName,
        'tokens' => categoryTokens($normalizedName),
    ];
}
$tagContextRows = $db->query("SELECT t.id AS tag_id, t.name AS tag_name, ta.alias, ta.direction FROM tags t LEFT JOIN tag_aliases ta ON ta.tag_id = t.id AND ta.active = 1 WHERE t.status = 'active' ORDER BY t.name ASC, ta.id ASC")->fetchAll(PDO::FETCH_ASSOC);
$tagContext = AiTaggingPipeline::buildAliasAwareTagContext($tagContextRows, 5, 2500);

$txnMap = [];
$aliasResolutions = [];
$learnedAliases = [];
$reviewRequired = [];

$prompt = "You are a financial assistant. For each transaction choose one short, broad, reusable canonical tag from the supplied allowlist and optionally describe it. Never invent a merchant-specific or transaction-specific tag. ";
$prompt .= "Aliases are examples that map to canonical tags. Always return the canonical tag name in the tag field, never an alias literal. ";
$prompt .= "Do not create a new tag. When none of the supplied tags fits, return REVIEW_REQUIRED in the tag field and put the broad proposed name in suggested_tag. ";
$prompt .= "Prioritise canonical tag selection accuracy over other metadata. Category is optional metadata and may be omitted. ";
$prompt .= "If you provide category, use canonical category names as reference guidance only; tagging should still proceed even when category is uncertain. ";
$prompt .= "Return JSON only as a top-level array of objects {\"id\":<id>,\"tag\":\"existing tag name or REVIEW_REQUIRED\",\"suggested_tag\":\"optional proposed broad name\",\"description\":\"tag description\",\"category\":\"optional category name\"} ";
$prompt .= "or as an object {\"transactions\":[...]} containing that array. Do not return a single object.\n\n";

if ($tagContext['text'] !== '') {
    $prompt .= "Canonical tags with alias examples (alias -> canonical):\n" . $tagContext['text'] . "\n\n";
}

$prompt .= "Canonical category references (optional, use only when confident):\n";
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

$processed = $processedLocally;
$categoryMapped = 0;
$categoryUnresolved = 0;
$unresolvedCategorySuggestions = [];
foreach ($suggestions as $s) {
    $txId = $s['id'] ?? null;
    $tagName = $s['tag'] ?? null;
    $catName = $s['category'] ?? null;
    $tagDesc = $s['description'] ?? null;

    if (!$txId || !$tagName) continue;

    $txn = $txnMap[$txId] ?? null;
    if (!$txn) continue;
    $resolved = AiTaggingPipeline::resolveCanonicalTag((string)$tagName, $tagContext['canonicalByName'], $tagContext['aliasToCanonical'], (float)$txn['amount']);
    if ($resolved !== null) {
        $tagId = (int)$resolved['id'];
        $canonicalTagName = $resolved['name'];
        if ($resolved['source'] === 'alias') {
            $aliasResolutions[] = ['input' => $tagName, 'canonical' => $canonicalTagName, 'id' => $tagId];
        }
        $tagName = $canonicalTagName;
        if ($tagDesc) {
            Tag::setDescriptionIfMissing($tagId, $tagDesc);
        }
    } else {
        $proposedName = trim((string)($s['suggested_tag'] ?? ''));
        if ($proposedName === '') {
            $proposedName = strcasecmp((string)$tagName, 'REVIEW_REQUIRED') === 0
                ? 'Unresolved pattern'
                : (string)$tagName;
        }
        $proposalKey = Tag::normalizeName($proposedName);
        if (!isset($reviewRequired[$proposalKey])) {
            $reviewRequired[$proposalKey] = [
                'suggested_tag' => $proposedName,
                'transactions' => 0,
                'category' => $catName ? (string)$catName : null,
            ];
        }
        $reviewRequired[$proposalKey]['transactions'] += (int)($txn['cnt'] ?? 1);
        Log::write('AI tag suggestion held for canonical review: ' . json_encode($reviewRequired[$proposalKey]), 'INFO');
        continue;
    }

    // Every accepted AI decision teaches the deterministic application matcher.
    // This is deliberately separate from the tag name so one canonical tag can
    // accumulate many merchants without creating a tag per transaction.
    $learned = Tag::learnTransactionAlias((int)$tagId, (string)$txn['description'], $txn['memo'], 'ai', (float)$txn['amount']);
    $learned['tx_id'] = (int)$txId;
    $learned['canonical'] = (string)$tagName;
    $learned['trigger'] = $resolved !== null ? $resolved['source'] : 'new_or_normalized_canonical';
    if ($learned['status'] === 'conflict' && !empty($learned['existing_tag_id'])) {
        $tagId = (int)$learned['existing_tag_id'];
        $learned['resolved_to_existing_tag'] = $tagId;
        Log::write('AI tag alias conflict resolved to existing canonical mapping: ' . json_encode($learned), 'WARNING');
    } elseif ($learned['status'] === 'overlap') {
        Log::write('AI tag alias overlap held for review: ' . json_encode($learned), 'WARNING');
    } elseif ($learned['status'] === 'created') {
        Log::write("AI learned reusable alias '{$learned['alias']}' for tag_id={$tagId}");
    }
    $learnedAliases[] = $learned;

    $catId = CategoryTag::getCategoryId((int)$tagId);
    if ($catId === null && $catName) {
        $categoryResolution = resolveCategoryId((string)$catName, $normalizedCategoryMap, $categoryTokenMap);
        if ($categoryResolution['matched'] && $categoryResolution['id'] !== null) {
            try {
                CategoryTag::add((int)$categoryResolution['id'], (int)$tagId);
                $categoryMapped++;
            } catch (Exception $e) {
                // Tag may already be assigned; ignore
            }
            $catId = CategoryTag::getCategoryId((int)$tagId);
        } else {
            $categoryUnresolved++;
            $unresolved = [
                'tx_id' => (int)$txId,
                'tag_id' => (int)$tagId,
                'suggested' => (string)$catName,
                'normalized' => $categoryResolution['normalized'],
                'closest' => $categoryResolution['closest'],
                'similarity' => round((float)$categoryResolution['score'], 4),
            ];
            $unresolvedCategorySuggestions[] = $unresolved;
            Log::write('AI category unresolved: ' . json_encode($unresolved), 'DEBUG');
        }
    }

    if ($catId !== null) {
        $upd = $db->prepare('UPDATE transactions SET tag_id = :tag, category_id = :cat WHERE description = :desc AND memo <=> :memo AND tag_id IS NULL AND transfer_id IS NULL');
        $upd->execute(['tag' => $tagId, 'cat' => (int)$catId, 'desc' => $txn['description'], 'memo' => $txn['memo']]);
    } else {
        $upd = $db->prepare('UPDATE transactions SET tag_id = :tag WHERE description = :desc AND memo <=> :memo AND tag_id IS NULL AND transfer_id IS NULL');
        $upd->execute(['tag' => $tagId, 'desc' => $txn['description'], 'memo' => $txn['memo']]);
    }
    $processed += $upd->rowCount();
}

// Replay the accumulated deterministic rules across every account. AI is used
// once for a pattern; matching transactions are handled locally from then on.
$replayed = Tag::applyToAllTransactions();
$processed += $replayed;
if ($replayed > 0) {
    CategoryTag::applyToAllTransactions();
}
Segment::applyToTransactions();

Log::write("AI tagged $processed transactions using $usage tokens");
if (!empty($learnedAliases)) {
    Log::write('AI alias learning summary: ' . json_encode($learnedAliases));
}
 $out = [
     'processed' => $processed,
     'tokens' => $usage,
     'category_mapped' => $categoryMapped,
     'category_unresolved' => $categoryUnresolved,
     'review_required' => array_values($reviewRequired),
     'review_required_count' => count($reviewRequired),
 ];
 if ($debugMode) {
     $out['debug'] = [
         'prompt' => $prompt,
         'response' => $content,
         'alias_context' => $tagContext['text'],
         'alias_context_truncated' => $tagContext['truncated'],
         'alias_resolutions' => $aliasResolutions,
         'learned_aliases' => $learnedAliases,
         'unresolved_categories' => $unresolvedCategorySuggestions,
     ];
 }
 echo json_encode($out);
// Self-check:
// Endpoint detected: Responses
// Using text.format.type = json_object for structured JSON tag suggestions
?>
