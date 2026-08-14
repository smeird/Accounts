<?php
// Non-destructive migration for reliable OFX transaction identity handling.
require_once __DIR__ . '/../Database.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$db = Database::getConnection();
$changes = [];

$result = $db->query("SHOW INDEX FROM `transactions` WHERE Key_name = 'unique_txn'");
if ($result->rowCount() > 0) {
    $db->exec("ALTER TABLE `transactions` DROP INDEX `unique_txn`");
    $changes[] = 'removed unique_txn';
}

$result = $db->query("SHOW INDEX FROM `transactions` WHERE Key_name = 'idx_transaction_fallback'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `transactions` ADD KEY `idx_transaction_fallback` (`account_id`, `date`, `amount`)");
    $changes[] = 'added idx_transaction_fallback';
}

echo $changes ? implode("\n", $changes) . "\n" : "Transaction identity schema already current.\n";
