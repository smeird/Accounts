<?php
require_once __DIR__ . '/../Database.php';

class TransactionGroup {
    public static function create(string $name): int {
        $db = Database::getConnection();
        $stmt = $db->prepare('INSERT INTO transaction_groups (name) VALUES (:name)');
        $stmt->execute(['name' => $name]);
        return (int)$db->lastInsertId();
    }
}
?>
