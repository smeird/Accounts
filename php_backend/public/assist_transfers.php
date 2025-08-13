<?php
// API endpoint to auto-link transfers with matching date and opposite amounts.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

try {
    $linked = Transaction::assistTransfers();
    echo json_encode(['status' => 'ok', 'linked' => $linked]);
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Assist transfers error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
}
?>
