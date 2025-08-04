<?php
// API endpoint returning transactions for a selected month and year.
require_once __DIR__ . '/../models/Log.php';
require_once __DIR__ . '/../models/Transaction.php';

header('Content-Type: application/json');

$month = isset($_GET['month']) ? (int)$_GET['month'] : date('n');
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

try {
    $transactions = Transaction::getByMonth($month, $year);
    echo json_encode($transactions);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

