<?php
// API endpoint returning the total number of untagged transactions.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

try {
    $count = Transaction::getUntaggedTotal();
    echo json_encode(['count' => $count]);
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Untagged count error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['count' => 0]);
}
?>
