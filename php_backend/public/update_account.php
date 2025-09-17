<?php
// API endpoint to update the name of an account.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/Account.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$accountId = $data['account_id'] ?? null;
$name = $data['name'] ?? null;

if (!$accountId || !$name) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

try {
    Account::rename((int)$accountId, $name);
    echo json_encode(['status' => 'ok']);
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Update account error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
}
?>
