<?php
// Model for account records stored in the database.
require_once __DIR__ . '/../Database.php';

class Account {
    public static function create(string $name): int {
        $db = Database::getConnection();
        $stmt = $db->prepare('INSERT INTO accounts (name) VALUES (:name)');
        $stmt->execute(['name' => $name]);
        return (int)$db->lastInsertId();
    }

    /**
     * Retrieve basic details for all accounts including transaction count and balance.
     */
    public static function getSummaries(): array {
        $db = Database::getConnection();
        $sql = 'SELECT a.`id`, a.`name`, COUNT(t.`id`) AS `transactions`, '
             . 'COALESCE(SUM(t.`amount`), 0) AS `balance`, '
             . 'MAX(t.`date`) AS `last_transaction` '
             . 'FROM `accounts` a '
             . 'LEFT JOIN `transactions` t ON t.`account_id` = a.`id` '
             . 'GROUP BY a.`id`, a.`name` '
             . 'ORDER BY a.`name`';
        $stmt = $db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
