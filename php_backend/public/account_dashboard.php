<?php
// API endpoint returning account summaries.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/Account.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

try {
    $accounts = Account::getSummaries();
    echo json_encode($accounts);
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Account dashboard error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
}
?>
