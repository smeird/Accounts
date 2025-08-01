<?php
require_once __DIR__ . '/../models/Transaction.php';

header('Content-Type: application/json');

$field = $_GET['field'] ?? '';
$value = $_GET['value'] ?? '';

if ($field === '' || $value === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Field and value are required']);
    exit;
}

try {
    $results = Transaction::search($field, $value);
    $total = 0.0;
    foreach ($results as $row) {
        $total += (float)$row['amount'];
    }
    echo json_encode(['results' => $results, 'total' => $total]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
