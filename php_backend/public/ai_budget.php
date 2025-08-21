<?php
// Endpoint that uses AI to set budgets based on past spending and a savings goal.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../models/Budget.php';
require_once __DIR__ . '/../models/Tag.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$goal = isset($data['goal']) ? (float)$data['goal'] : 0.0;
$month = isset($data['month']) ? (int)$data['month'] : (int)date('n');
$year = isset($data['year']) ? (int)$data['year'] : (int)date('Y');

try {
    $db = Database::getConnection();
    $ignore = Tag::getIgnoreId();
    $stmt = $db->prepare('SELECT c.id AS category_id, c.name, '
        . 'COALESCE(SUM(CASE WHEN t.amount < 0 THEN -t.amount ELSE 0 END),0) AS spent '
        . 'FROM categories c '
        . 'LEFT JOIN transactions t ON c.id = t.category_id '
        . 'AND MONTH(t.date) = :month AND YEAR(t.date) = :year '
        . 'AND t.transfer_id IS NULL '
        . 'AND (t.tag_id IS NULL OR t.tag_id != :ignore) '
        . 'GROUP BY c.id, c.name ORDER BY c.name');
    $stmt->execute(['month' => $month, 'year' => $year, 'ignore' => $ignore]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalSpent = array_sum(array_column($rows, 'spent'));
    $available = max($totalSpent - $goal, 0);
    $budgets = [];
    foreach ($rows as $row) {
        $amount = $totalSpent > 0 ? ($row['spent'] / $totalSpent) * $available : 0;
        Budget::set((int)$row['category_id'], $month, $year, $amount);
        $budgets[] = [
            'category_id' => (int)$row['category_id'],
            'category' => $row['name'],
            'amount' => $amount,
            'spent' => (float)$row['spent'],
            'left' => $amount - (float)$row['spent']
        ];
    }
    Log::write("AI budgets applied for $month/$year with goal $goal");
    echo json_encode(['status' => 'ok', 'budgets' => $budgets]);
} catch (Exception $e) {
    http_response_code(500);
    Log::write('AI budgeting error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
}
?>
