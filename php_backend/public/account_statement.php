<?php
// Returns a list of transactions for a single account.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/Transaction.php';

header('Content-Type: application/json');

try {
    if (!isset($_GET['id'])) {
        throw new Exception('Account id required');
    }
    $id = (int)$_GET['id'];
    $transactions = Transaction::getByAccount($id);
    echo json_encode($transactions);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
