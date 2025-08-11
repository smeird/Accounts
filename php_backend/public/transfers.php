<?php
// API endpoint to list transfer pairs and OFX-marked transfer transactions.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

try {
    $transfers = Transaction::getTransfers();
    $ofxTransfers = Transaction::getOfxTransfers();
    echo json_encode(['transfers' => $transfers, 'ofx_transfers' => $ofxTransfers]);
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Fetch transfers error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
}
?>
