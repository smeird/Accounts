<?php
// Uses AI once to map unassigned canonical tags onto existing categories.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../AiCategoryTagger.php';
require_once __DIR__ . '/../models/CategoryTag.php';
require_once __DIR__ . '/../models/Setting.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $apiKey = Setting::get('openai_api_token');
    if (!$apiKey) {
        throw new InvalidArgumentException('An OpenAI API token must be configured first');
    }
    $db = Database::getConnection();
    $categoryRows = $db->query(
        'SELECT c.`id`, c.`name`, c.`description`, t.`name` AS tag_name '
        . 'FROM `categories` c '
        . 'LEFT JOIN `category_tags` ct ON ct.`category_id` = c.`id` '
        . 'LEFT JOIN `tags` t ON t.`id` = ct.`tag_id` '
        . 'ORDER BY c.`name`, t.`name`'
    )->fetchAll(PDO::FETCH_ASSOC);
    $categories = [];
    foreach ($categoryRows as $row) {
        $id = (int)$row['id'];
        if (!isset($categories[$id])) {
            $categories[$id] = [
                'id' => $id,
                'name' => (string)$row['name'],
                'description' => $row['description'],
                'assigned_tags' => [],
            ];
        }
        if (!empty($row['tag_name'])) {
            $categories[$id]['assigned_tags'][] = (string)$row['tag_name'];
        }
    }
    if (empty($categories)) {
        throw new InvalidArgumentException('Create at least one category before categorising tags');
    }

    $limit = (int)(Setting::get('ai_category_tag_batch_size') ?? 100);
    $limit = max(1, min(250, $limit));
    $candidates = $db->query(
        'SELECT t.`id`, t.`name`, t.`keyword`, t.`description`, COUNT(tx.`id`) AS transactions '
        . 'FROM `tags` t '
        . 'LEFT JOIN `category_tags` ct ON ct.`tag_id` = t.`id` '
        . 'LEFT JOIN `transactions` tx ON tx.`tag_id` = t.`id` '
        . 'WHERE ct.`tag_id` IS NULL AND LOWER(t.`name`) != \'ignore\' '
        . 'GROUP BY t.`id`, t.`name`, t.`keyword`, t.`description` '
        . 'ORDER BY transactions DESC, t.`name` ASC LIMIT ' . $limit
    )->fetchAll(PDO::FETCH_ASSOC);
    if (empty($candidates)) {
        echo json_encode(['assigned' => 0, 'updated_transactions' => 0, 'remaining' => 0, 'tokens' => 0, 'assignments' => []]);
        exit;
    }

    $categories = array_values($categories);
    $prompt = AiCategoryTagger::buildPrompt($categories, $candidates);
    $temperature = Setting::get('ai_temperature');
    if ($temperature === null || $temperature === '') {
        $temperature = 1;
    }
    $payload = [
        'model' => Setting::get('ai_model') ?? 'gpt-5-nano',
        'input' => [
            ['role' => 'system', 'content' => 'You map canonical financial tags to an existing category allowlist. Return JSON only.'],
            ['role' => 'user', 'content' => $prompt],
        ],
        'temperature' => (float)$temperature,
        'text' => ['format' => ['type' => 'json_object']],
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 120,
    ]);
    $rawResponse = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($rawResponse === false || $statusCode !== 200) {
        Log::write('AI category tag API error: HTTP ' . $statusCode . ' ' . ($rawResponse ?: 'no response'), 'ERROR');
        throw new RuntimeException('OpenAI could not categorise the tags');
    }
    $response = json_decode($rawResponse, true);
    if (!is_array($response)) {
        throw new RuntimeException('OpenAI returned an invalid response');
    }
    $content = AiCategoryTagger::extractOutputText($response);
    $suggestions = json_decode($content, true);
    if (!is_array($suggestions)) {
        Log::write('AI category tag invalid JSON: ' . $content, 'ERROR');
        throw new RuntimeException('OpenAI returned invalid category suggestions');
    }

    $candidateIds = array_map(function($row) { return (int)$row['id']; }, $candidates);
    $categoryIds = array_map(function($row) { return (int)$row['id']; }, $categories);
    $validated = AiCategoryTagger::validateAssignments($suggestions, $candidateIds, $categoryIds);
    $tagNames = [];
    foreach ($candidates as $candidate) {
        $tagNames[(int)$candidate['id']] = (string)$candidate['name'];
    }
    $categoryNames = [];
    foreach ($categories as $category) {
        $categoryNames[(int)$category['id']] = (string)$category['name'];
    }

    $applied = [];
    foreach ($validated['accepted'] as $assignment) {
        $tagId = (int)$assignment['tag_id'];
        $categoryId = (int)$assignment['category_id'];
        if (CategoryTag::getCategoryId($tagId) !== null) {
            continue;
        }
        try {
            CategoryTag::add($categoryId, $tagId);
            $applied[] = [
                'tag_id' => $tagId,
                'tag' => $tagNames[$tagId] ?? ('Tag ' . $tagId),
                'category_id' => $categoryId,
                'category' => $categoryNames[$categoryId] ?? ('Category ' . $categoryId),
                'confidence' => $assignment['confidence'],
                'reason' => $assignment['reason'],
            ];
        } catch (Exception $e) {
            Log::write('AI category tag assignment skipped: ' . $e->getMessage(), 'WARNING');
        }
    }
    $updatedTransactions = !empty($applied) ? CategoryTag::applyToAllTransactions() : 0;
    $remaining = (int)$db->query(
        'SELECT COUNT(*) FROM `tags` t LEFT JOIN `category_tags` ct ON ct.`tag_id` = t.`id` '
        . 'WHERE ct.`tag_id` IS NULL AND LOWER(t.`name`) != \'ignore\''
    )->fetchColumn();
    $tokens = (int)($response['usage']['total_tokens'] ?? 0);
    Log::write('AI assigned ' . count($applied) . " tags to existing categories using $tokens tokens; updated $updatedTransactions transactions");

    $output = [
        'assigned' => count($applied),
        'updated_transactions' => $updatedTransactions,
        'remaining' => $remaining,
        'tokens' => $tokens,
        'assignments' => $applied,
        'skipped_suggestions' => count($validated['rejected']),
    ];
    if (Setting::get('ai_debug') === '1') {
        $output['debug'] = [
            'prompt' => $prompt,
            'response' => $content,
            'rejected' => $validated['rejected'],
        ];
    }
    echo json_encode($output);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    Log::write('AI category tag error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => $e->getMessage()]);
}
?>
