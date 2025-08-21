<?php
// Endpoint that uses AI to set budgets based on past spending and a savings goal.
require_once __DIR__ . '/../nocache.php';
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


    $prompt = "You are a financial assistant. Allocate budgets for next month so total spending is about £$available leaving £$goal for savings. Use the last 12 months of totals to respect fixed costs. Return JSON object {\"budgets\":[{\"id\":<category_id>,\"amount\":<budget>}],\"summary\":\"short plain English explanation of the allocation avoiding listing every category\"}\n\n";

    foreach ($history as $h) {
        $prompt .= "{$h['id']} {$h['name']}: [" . implode(',', $h['totals']) . "]\n";
    }

    $payload = [
        'model' => 'gpt-5-nano',
        'messages' => [

            ['role' => 'system', 'content' => 'You create budgets and explanations in JSON.'],

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
        Log::write('AI budget API error: ' . ($response ?: 'no response'), 'ERROR');
        echo json_encode(['error' => 'OpenAI request failed']);
        exit;
    }
    $data = json_decode($response, true);
    $content = $data['choices'][0]['message']['content'] ?? '';
    $usage = $data['usage']['total_tokens'] ?? 0;

    $content = trim($content);
    if (substr($content, 0, 3) === '```') {
        $content = preg_replace('/^```(?:json)?\\s*/i', '', $content);
        $content = preg_replace('/```\\s*$/', '', $content);
        $content = trim($content);
    }
    $suggestions = json_decode($content, true);

    if (!is_array($suggestions) || !isset($suggestions['budgets']) || !is_array($suggestions['budgets'])) {

        http_response_code(500);
        Log::write('AI budget invalid response: ' . $content, 'ERROR');
        echo json_encode(['error' => 'Invalid AI response']);
        exit;
    }

    $summary = isset($suggestions['summary']) ? (string)$suggestions['summary'] : '';
    foreach ($suggestions['budgets'] as $s) {

        $cid = (int)($s['id'] ?? 0);
        $amount = isset($s['amount']) ? (float)$s['amount'] : null;
        if ($cid > 0 && $amount !== null) {
            Budget::set($cid, $month, $year, $amount);
        }
    }

    $budgets = Budget::getMonthly($month, $year);
    Log::write("AI budgets applied for $month/$year with goal $goal using $usage tokens");

    echo json_encode(['status' => 'ok', 'budgets' => $budgets, 'summary' => $summary]);

} catch (Exception $e) {
    http_response_code(500);
    Log::write('AI budgeting error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
}
?>
