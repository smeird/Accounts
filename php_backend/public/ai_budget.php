<?php
// Endpoint that uses AI to set budgets based on past spending and a savings goal.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../models/Budget.php';
require_once __DIR__ . '/../models/Tag.php';
require_once __DIR__ . '/../models/Log.php';

require_once __DIR__ . '/../models/Setting.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}


$data = json_decode(file_get_contents('php://input'), true) ?? [];
$goal = isset($data['goal']) ? (float)$data['goal'] : 0.0;
$month = isset($data['month']) ? (int)$data['month'] : (int)date('n');
$year = isset($data['year']) ? (int)$data['year'] : (int)date('Y');


$apiKey = Setting::get('openai_api_token');
if (!$apiKey) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing API token']);
    exit;
}

try {
    $db = Database::getConnection();
    $ignore = Tag::getIgnoreId();

    $target = DateTime::createFromFormat('Y-n', "$year-$month");
    $historyStart = (clone $target)->modify('-12 months');
    $periods = [];
    $periodMap = [];
    for ($i = 0; $i < 12; $i++) {
        $key = $historyStart->format('Y-m');
        $periods[] = $key;
        $periodMap[$key] = $i;
        $historyStart->modify('+1 month');
    }
    $startDate = $periods[0] . '-01';
    $endDate = $target->format('Y-m-01');

    $cats = $db->query('SELECT id, name FROM categories ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    $history = [];
    foreach ($cats as $c) {
        $history[$c['id']] = [
            'id' => (int)$c['id'],
            'name' => $c['name'],
            'totals' => array_fill(0, 12, 0)
        ];
    }

    $stmt = $db->prepare('SELECT category_id, YEAR(`date`) as yr, MONTH(`date`) as mo, '
        . 'SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END) as spent '
        . 'FROM transactions '
        . 'WHERE `date` >= :start AND `date` < :end '
        . 'AND transfer_id IS NULL '
        . 'AND (tag_id IS NULL OR tag_id != :ignore) '
        . 'GROUP BY category_id, yr, mo');
    $stmt->execute(['start' => $startDate, 'end' => $endDate, 'ignore' => $ignore]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $key = sprintf('%04d-%02d', $row['yr'], $row['mo']);
        if (!isset($periodMap[$key])) continue;
        $cid = (int)$row['category_id'];
        if (isset($history[$cid])) {
            $history[$cid]['totals'][$periodMap[$key]] = (float)$row['spent'];
        }
    }
    $history = array_values($history);

    $totalSpent = 0;
    foreach ($history as $h) {
        $totalSpent += $h['totals'][11];
    }
    $available = max($totalSpent - $goal, 0);


    $prompt = "You are a financial assistant. Allocate budgets for next month so total spending is about £$available leaving £$goal for savings. Use the last 12 months of totals to respect fixed costs. Return JSON only as a top-level array of {\"id\":<category_id>,\"amount\":<budget>} or as {\"budgets\":[...],\"summary\":\"short plain English explanation of the allocation avoiding listing every category\"}.\n\n";

    foreach ($history as $h) {
        $prompt .= "{$h['id']} {$h['name']}: [" . implode(',', $h['totals']) . "]\n";
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
            ['role' => 'system', 'content' => 'You create budgets and explanations in JSON.'],
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
        Log::write('AI budget API error: ' . ($response ?: 'no response'), 'ERROR');
        echo json_encode(['error' => 'OpenAI request failed']);
        exit;
    }
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(500);
        Log::write('AI budget API JSON decode error: ' . json_last_error_msg() . ' | ' . $response, 'ERROR');
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
        Log::write('AI budget empty response: ' . $response, 'ERROR');
        echo json_encode(['error' => 'Invalid AI response']);
        exit;
    }

    $content = trim($content);
    if (substr($content, 0, 3) === '```') {
        $content = preg_replace('/^```(?:json)?\\s*/i', '', $content);
        $content = preg_replace('/```\\s*$/', '', $content);
        $content = trim($content);
    }
    $suggestions = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(500);
        Log::write('AI budget invalid JSON: ' . json_last_error_msg() . ' | ' . $content, 'ERROR');
        echo json_encode(['error' => 'Invalid AI response']);
        exit;
    }
    $summary = '';
    if (isset($suggestions['budgets']) && is_array($suggestions['budgets'])) {
        $summary = isset($suggestions['summary']) ? (string)$suggestions['summary'] : '';
        $suggestions = $suggestions['budgets'];
    }
    if (!is_array($suggestions)) {
        http_response_code(500);
        Log::write('AI budget invalid response: ' . $content, 'ERROR');
        echo json_encode(['error' => 'Invalid AI response']);
        exit;
    }
    foreach ($suggestions as $s) {

        $cid = (int)($s['id'] ?? 0);
        $amount = isset($s['amount']) ? (float)$s['amount'] : null;
        if ($cid > 0 && $amount !== null) {
            Budget::set($cid, $month, $year, $amount);
        }
    }

    $budgets = Budget::getMonthly($month, $year);
    $out = ['status' => 'ok', 'budgets' => $budgets, 'summary' => $summary];
    if ($debugMode) {
        $out['debug'] = ['prompt' => $prompt, 'response' => $content];
    }
    Log::write("AI budgets applied for $month/$year with goal $goal using $usage tokens");
    echo json_encode($out);

} catch (Exception $e) {
    http_response_code(500);
    $info = ($e instanceof PDOException && $e->errorInfo) ? json_encode($e->errorInfo) : '';
    Log::write('AI budgeting error: ' . $e->getMessage() . ($info ? ' SQL: ' . $info : ''), 'ERROR');
    echo json_encode(['error' => 'Server error']);
}
// Self-check:
// Endpoint detected: Responses
// Using text.format.type = json_object for structured JSON budget suggestions
?>
