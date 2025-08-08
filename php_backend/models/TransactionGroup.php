<?php
// Model for grouping transactions under named collections.
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

    public static function delete(int $id): bool {
        $db = Database::getConnection();
        // clear references from transactions
        $stmt = $db->prepare('UPDATE transactions SET group_id = NULL WHERE group_id = :id');
        $stmt->execute(['id' => $id]);

        // delete the group itself
        $stmt = $db->prepare('DELETE FROM transaction_groups WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }

    public static function find(int $id): ?array {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT id, name FROM transaction_groups WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function all(): array {
        $db = Database::getConnection();
        $stmt = $db->query('SELECT id, name FROM transaction_groups ORDER BY id');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
