<?php
// Returns a list of transactions for a single account.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

try {
    if (!isset($_GET['id'])) {
        throw new Exception('Account id required');
    }
    $id = (int)$_GET['id'];
    $months = null;
    if (isset($_GET['months']) && $_GET['months'] !== 'all') {
        $months = max(1, (int)$_GET['months']);
    }
    $startDate = null;
    if ($months) {
        $startDate = (new DateTime())->modify("-{$months} months")->format('Y-m-d');
    }
    $transactions = Transaction::getByAccount($id, $startDate);
    echo json_encode($transactions);
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Account statement error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
}
?>
