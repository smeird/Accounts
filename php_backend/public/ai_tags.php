<?php
// Use OpenAI to suggest tags and categories for untagged transactions.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../models/Tag.php';
require_once __DIR__ . '/../models/CategoryTag.php';
require_once __DIR__ . '/../models/Setting.php';
require_once __DIR__ . '/../models/Log.php';
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

$txnMap = [];
$prompt = "You are a financial assistant. For each transaction provide a short tag, a brief description for the tag and one of the provided categories. If the transaction details are ambiguous, use a generic tag name. Return JSON array with objects {\"id\":<id>,\"tag\":\"tag name\",\"description\":\"tag description\",\"category\":\"category name\"}.\n\n";

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
$content = $data['output_text'] ?? ($data['output'][0]['content'][0]['text'] ?? '');
$usage = $data['usage']['total_tokens'] ?? 0;


// Strip Markdown code fences if present
$content = trim($content);
if (substr($content, 0, 3) === '```') {
    $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
    $content = preg_replace('/```\s*$/', '', $content);
    $content = trim($content);
}


$suggestions = json_decode($content, true);
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

    if (!$txId || !$tagName || !$catName) continue;

    $txn = $txnMap[$txId] ?? null;
    if (!$txn) continue;
    $keyword = substr($txn['description'], 0, 100);

    $tagId = Tag::getIdByName($tagName);
    if ($tagId === null) {
        $tagId = Tag::create($tagName, $keyword, $tagDesc);
    } else {
        Tag::setKeywordIfMissing($tagId, $keyword);
        if ($tagDesc) {
            Tag::setDescriptionIfMissing($tagId, $tagDesc);
        }
    }

    $stmt = $db->prepare('SELECT id FROM categories WHERE name = :name LIMIT 1');
    $stmt->execute(['name' => $catName]);
    $catId = $stmt->fetchColumn();
    if ($catId === false) continue;

    try {
        CategoryTag::add((int)$catId, (int)$tagId);
    } catch (Exception $e) {
        // Tag may already be assigned; ignore
    }

    $upd = $db->prepare('UPDATE transactions SET tag_id = :tag, category_id = :cat WHERE description = :desc AND memo <=> :memo AND tag_id IS NULL');
    $upd->execute(['tag' => $tagId, 'cat' => (int)$catId, 'desc' => $txn['description'], 'memo' => $txn['memo']]);
    $processed += $upd->rowCount();
}

Log::write("AI tagged $processed transactions using $usage tokens");
 $out = ['processed' => $processed, 'tokens' => $usage];
 if ($debugMode) {
     $out['debug'] = ['prompt' => $prompt, 'response' => $content];
 }
 echo json_encode($out);
// Self-check:
// Endpoint detected: Responses
// Using text.format.type = json_object for structured JSON tag suggestions
?>
