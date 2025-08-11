<?php
// API endpoint returning account summaries.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/Account.php';

header('Content-Type: application/json');

try {
    $accounts = Account::getSummaries();
    echo json_encode($accounts);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
