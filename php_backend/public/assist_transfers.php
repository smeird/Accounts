<?php
// API endpoint to search for transactions that look like transfers.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

try {
    $candidates = Transaction::getTransferCandidates();
    echo json_encode(['status' => 'ok', 'candidates' => $candidates]);
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Assist transfers error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
}
?>
