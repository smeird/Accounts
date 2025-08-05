<?php
// API endpoint returning a single transaction's details.
require_once __DIR__ . '/../models/Transaction.php';

header('Content-Type: application/json');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid id']);
    exit;
}

try {
    $tx = Transaction::get($id);
    if ($tx) {
        echo json_encode($tx);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Transaction not found']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
