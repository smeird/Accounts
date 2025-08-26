<?php
// Returns transactions within a date range as JSON for client-side export
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

$start = $_GET['start'] ?? '1970-01-01';
$end   = $_GET['end'] ?? date('Y-m-d');

try {
    $txns = Transaction::getByDateRange($start, $end);
    echo json_encode($txns);
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Export data error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
}
