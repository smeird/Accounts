<?php
require_once __DIR__ . '/../Database.php';

class TransactionGroup {
    public static function create(string $name): int {
        $db = Database::getConnection();
        $stmt = $db->prepare('INSERT INTO transaction_groups (name) VALUES (:name)');
        $stmt->execute(['name' => $name]);
        return (int)$db->lastInsertId();
    }

    public static function update(int $id, string $name): void {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE transaction_groups SET name = :name WHERE id = :id');
        $stmt->execute(['id' => $id, 'name' => $name]);
    }
}
?>
