<?php
// Returns running balance history for a single account starting from latest bank balance.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/Account.php';
require_once __DIR__ . '/../models/Tag.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

try {
    if (!isset($_GET['id'])) {
        throw new Exception('Account id required');
    }
    $id = (int)$_GET['id'];
    $db = Database::getConnection();
    $stmt = $db->prepare('SELECT name, sort_code, account_number, ledger_balance, ledger_balance_date FROM accounts WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$account) {
        throw new Exception('Account not found');
    }
    $stmt = $db->prepare('SELECT id, date, amount FROM transactions WHERE account_id = :id ORDER BY date, id');
    $stmt->execute(['id' => $id]);
    $history = Account::buildBalanceHistory(
        (float)$account['ledger_balance'],
        $account['ledger_balance_date'] ?: null,
        $stmt->fetchAll(PDO::FETCH_ASSOC)
    );
    echo json_encode([
        'name' => $account['name'],
        'sort_code' => $account['sort_code'],
        'account_number' => $account['account_number'],
        'history' => $history
    ]);
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Account balance error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
}
?>
