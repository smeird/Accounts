<?php
// API endpoint to list all detected transfer pairs.
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

try {
    $transfers = Transaction::getTransfers();
    echo json_encode(['transfers' => $transfers]);
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Fetch transfers error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
}
?>
