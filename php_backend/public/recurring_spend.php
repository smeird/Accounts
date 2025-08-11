<?php
// API endpoint to analyse recurring spending over the past year.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/Transaction.php';

header('Content-Type: application/json');

try {
    $results = Transaction::getRecurringSpend();
    $total = 0.0;
    foreach ($results as $row) {
        $total += (float)$row['total'];
    }
    echo json_encode(['results' => $results, 'total' => $total]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
