<?php
// API endpoint to search transactions across all fields.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/Log.php';
require_once __DIR__ . '/../models/Transaction.php';

header('Content-Type: application/json');

$value = $_GET['value'] ?? '';
$amount = isset($_GET['amount']) ? $_GET['amount'] : null;
$min = isset($_GET['min_amount']) ? $_GET['min_amount'] : null;
$max = isset($_GET['max_amount']) ? $_GET['max_amount'] : null;

if ($amount !== null) {
    $min = $max = $amount;
}

if ($value === '' && $min === null && $max === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Search value or amount range is required']);
    exit;
}

try {
    $results = Transaction::search(
        $value,
        $min !== null ? (float)$min : null,
        $max !== null ? (float)$max : null
    );
    $total = 0.0;
    foreach ($results as $row) {
        if ($row['transfer_id'] === null) {
            $total += (float)$row['amount'];
        }
    }
    echo json_encode(['results' => $results, 'total' => $total]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
