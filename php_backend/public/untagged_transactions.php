<?php
// API endpoint returning most common untagged transactions grouped by description.
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

try {
    $rows = Transaction::getUntaggedCounts();
    echo json_encode($rows);
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Untagged list error: ' . $e->getMessage(), 'ERROR');
    echo json_encode([]);
}
?>
