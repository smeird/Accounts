<?php
require_once __DIR__ . '/../models/Transaction.php';

header('Content-Type: application/json');

try {
    $months = Transaction::getAvailableMonths();
    echo json_encode($months);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
