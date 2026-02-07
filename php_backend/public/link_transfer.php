<?php
// API endpoint to manually link two transactions as a transfer.
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
$id1 = $data['id1'] ?? null;
$id2 = $data['id2'] ?? null;

if (!$id1 || !$id2) {
    http_response_code(400);
    echo json_encode(['error' => 'Both transaction IDs are required']);
    exit;
}

try {
    if (Transaction::linkTransfer((int)$id1, (int)$id2)) {
        echo json_encode(['status' => 'ok']);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Unable to link transactions. Ensure they are in different accounts, have opposite amounts, and are not linked to another transfer.']);
    }
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Link transfer error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
}
?>
