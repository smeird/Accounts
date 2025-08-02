<?php
require_once __DIR__ . '/../models/Log.php';
require_once __DIR__ . '/../models/Transaction.php';

header('Content-Type: application/json');

$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

try {
    $data = Transaction::getMonthlySpending($year);
    echo json_encode($data);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
