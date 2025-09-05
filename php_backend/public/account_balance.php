<?php
// Returns running balance history for a single account starting from latest bank balance.
require_once __DIR__ . '/../nocache.php';
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
    $months = null;
    if (isset($_GET['months']) && $_GET['months'] !== 'all') {
        $months = max(1, (int)$_GET['months']);
    }
    $cutoff = null;
    if ($months) {
        $cutoff = (new DateTime())->modify("-{$months} months")->format('Y-m-d');
    }

    $db = Database::getConnection();
    $stmt = $db->prepare('SELECT name, sort_code, account_number, ledger_balance, ledger_balance_date FROM accounts WHERE id = :id');
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
    $ignore = Tag::getIgnoreId();
    $stmt = $db->prepare('SELECT date, amount FROM transactions WHERE account_id = :id AND (tag_id IS NULL OR tag_id != :ignore) ORDER BY date DESC, id DESC');
    $stmt->execute(['id' => $id, 'ignore' => $ignore]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $balance -= (float)$row['amount'];
        if (!$cutoff || $row['date'] >= $cutoff) {
            $history[] = ['date' => $row['date'], 'balance' => $balance];
        }
    }
    $history = array_reverse($history);
    if ($cutoff) {
        $history = array_values(array_filter($history, function($h) use ($cutoff){
            return $h['date'] >= $cutoff;
        }));
    }
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
