<?php
// Endpoint that uses AI to summarise finances based on segment and category totals.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../models/Tag.php';
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

try {
    $db = Database::getConnection();
    $ignore = Tag::getIgnoreId();

    $end = new DateTime('first day of next month');
    $start = (clone $end)->modify('-12 months');
    $startDate = $start->format('Y-m-d');
    $endDate = $end->format('Y-m-d');

    $segStmt = $db->prepare('SELECT COALESCE(s.name, "Unsegmented") AS name, SUM(t.amount) AS total '
        . 'FROM transactions t '
        . 'LEFT JOIN segments s ON t.segment_id = s.id '
        . 'WHERE t.date >= :start AND t.date < :end '
        . 'AND t.transfer_id IS NULL '
        . 'AND (t.tag_id IS NULL OR t.tag_id != :ignore) '
        . 'GROUP BY name ORDER BY total DESC');
    $segStmt->execute(['start' => $startDate, 'end' => $endDate, 'ignore' => $ignore]);
    $segments = $segStmt->fetchAll(PDO::FETCH_ASSOC);

    $catStmt = $db->prepare('SELECT COALESCE(c.name, "Uncategorised") AS name, SUM(t.amount) AS total '
        . 'FROM transactions t '
        . 'LEFT JOIN categories c ON t.category_id = c.id '
        . 'WHERE t.date >= :start AND t.date < :end '
        . 'AND t.transfer_id IS NULL '
        . 'AND (t.tag_id IS NULL OR t.tag_id != :ignore) '
        . 'GROUP BY name ORDER BY total DESC');
    $catStmt->execute(['start' => $startDate, 'end' => $endDate, 'ignore' => $ignore]);
    $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

    $prompt = "Provide an overall summary and financial analysis based on these segment and category totals for the last 12 months. "
        . "Respond with a detailed paragraph and do not ask any questions.\n\nSegments:\n";
    foreach ($segments as $s) {
        $prompt .= $s['name'] . ': £' . number_format((float)$s['total'], 2) . "\n";
    }
    $prompt .= "\nCategories:\n";
    foreach ($categories as $c) {
        $prompt .= $c['name'] . ': £' . number_format((float)$c['total'], 2) . "\n";
    }

    $payload = [
        'model' => 'gpt-5-mini',
        'messages' => [
            ['role' => 'system', 'content' => 'You are a financial analyst that writes long, clear summaries without asking questions.'],
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 1,
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($response === false || $code !== 200) {
        http_response_code(500);
        Log::write('AI feedback API error: ' . ($response ?: 'no response'), 'ERROR');
        echo json_encode(['error' => 'OpenAI request failed']);
        exit;
    }
    $data = json_decode($response, true);
    $content = trim($data['choices'][0]['message']['content'] ?? '');
    $usage = $data['usage']['total_tokens'] ?? 0;

    Log::write("AI feedback generated using $usage tokens");
    echo json_encode(['feedback' => $content, 'tokens' => $usage]);

} catch (Exception $e) {
    http_response_code(500);
    Log::write('AI feedback error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
}
?>
