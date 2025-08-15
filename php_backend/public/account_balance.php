<?php
// Returns running balance history for a single account starting from latest bank balance.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/Account.php';
require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json');

try {
    if (!isset($_GET['id'])) {
        throw new Exception('Account id required');
    }
    $id = (int)$_GET['id'];
    $db = Database::getConnection();
    $stmt = $db->prepare('SELECT name, ledger_balance, ledger_balance_date FROM accounts WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$account) {
        throw new Exception('Account not found');
    }
    $balance = (float)$account['ledger_balance'];
    $history = [];
    if ($account['ledger_balance_date']) {
        $history[] = ['date' => $account['ledger_balance_date'], 'balance' => $balance];
    }
    $stmt = $db->prepare('SELECT date, amount FROM transactions WHERE account_id = :id ORDER BY date DESC, id DESC');
    $stmt->execute(['id' => $id]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $balance -= (float)$row['amount'];
        $history[] = ['date' => $row['date'], 'balance' => $balance];
    }
    $history = array_reverse($history);
    echo json_encode(['name' => $account['name'], 'history' => $history]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
