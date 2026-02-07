<?php

// API endpoint to mark a single transaction as a transfer.

require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$id = isset($data['id']) ? (int)$data['id'] : 0;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'A valid transaction id is required']);
    exit;
}

try {
    $updated = Transaction::markTransfers([$id]);
    if ($updated < 1) {
        http_response_code(400);
        echo json_encode(['error' => 'Transaction could not be marked as a transfer']);
        exit;
    }

    echo json_encode(['status' => 'ok', 'updated' => $updated]);
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Mark single transfer error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
}
?>
