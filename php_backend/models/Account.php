<?php
// Model for account records stored in the database.
require_once __DIR__ . '/../Database.php';

class Account {
    /**
     * Create a new account with the provided name.
     */
    public static function create(string $name, ?string $sortCode = null, ?string $accountNumber = null): int {
        $db = Database::getConnection();
        $stmt = $db->prepare('INSERT INTO accounts (name, sort_code, account_number) VALUES (:name, :sort_code, :account_number)');
        $stmt->execute(['name' => $name, 'sort_code' => $sortCode, 'account_number' => $accountNumber]);
        return (int)$db->lastInsertId();
    }

    /**
     * Retrieve basic details for all accounts including transaction count and bank-reported balance.
     */
    public static function getSummaries(): array {
        $db = Database::getConnection();
        $sql = 'SELECT a.`id`, a.`name`, a.`sort_code`, a.`account_number`, COUNT(t.`id`) AS `transactions`, '
             . 'COALESCE(a.`ledger_balance`, 0) AS `balance`, '
             . 'MAX(t.`date`) AS `last_transaction`, '
             . 'CASE WHEN a.`sort_code` IS NULL OR a.`sort_code` = "" THEN 1 ELSE 0 END AS `is_credit_card` '
             . 'FROM `accounts` a '
             . 'LEFT JOIN `transactions` t ON t.`account_id` = a.`id` '
             . 'GROUP BY a.`id`, a.`name`, a.`sort_code`, a.`account_number`, a.`ledger_balance` '
             . 'ORDER BY a.`name`';
        $stmt = $db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update the stored ledger balance for an account.
     */
    public static function updateLedgerBalance(int $accountId, float $balance, string $date): void {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE accounts SET ledger_balance = :bal, ledger_balance_date = :dt WHERE id = :id');
        $stmt->execute(['bal' => $balance, 'dt' => $date, 'id' => $accountId]);
    }

    /**
     * Rename an existing account.
     */
    public static function rename(int $accountId, string $name): void {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE accounts SET name = :name WHERE id = :id');
        $stmt->execute(['name' => $name, 'id' => $accountId]);
    }
}
?>
