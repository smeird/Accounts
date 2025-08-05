<?php
// API endpoint to search transactions across all fields.
require_once __DIR__ . '/../models/Log.php';
require_once __DIR__ . '/../models/Transaction.php';

header('Content-Type: application/json');

$value = $_GET['value'] ?? '';

if ($value === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Search value is required']);
    exit;
}

try {
    $results = Transaction::search($value);
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
