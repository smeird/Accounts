<?php
// Model for grouping transactions under named collections.
require_once __DIR__ . '/../Database.php';

class TransactionGroup {
    /**
     * Create a new transaction group and return its ID.
     */
    public static function create(string $name, ?string $description = null, bool $active = true): int {
        $db = Database::getConnection();
        $stmt = $db->prepare('INSERT INTO transaction_groups (name, description, active) VALUES (:name, :description, :active)');
        $stmt->execute([
            'name' => $name,
            'description' => $description,
            'active' => $active ? 1 : 0
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * Rename an existing transaction group.
     */
    public static function update(int $id, string $name, ?string $description = null, bool $active = true): void {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE transaction_groups SET name = :name, description = :description, active = :active WHERE id = :id');
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'active' => $active ? 1 : 0
        ]);
    }

    /**
     * Mark a group as active or inactive.
     */
    public static function setActive(int $id, bool $active): void {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE transaction_groups SET active = :active WHERE id = :id');
        $stmt->execute(['id' => $id, 'active' => $active ? 1 : 0]);
    }

    /**
     * Delete a transaction group and clear any references to it.
     */
    public static function delete(int $id): bool {
        $db = Database::getConnection();
        // clear references from transactions
        $stmt = $db->prepare('UPDATE transactions SET group_id = NULL WHERE group_id = :id');
        $stmt->execute(['id' => $id]);

        // delete the group itself
        $stmt = $db->prepare('DELETE FROM transaction_groups WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }

    /**
     * Find a transaction group by ID.
     */
    public static function find(int $id): ?array {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT id, name, description, active FROM transaction_groups WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['active'] = (int)$row['active'];
        }
        return $row ?: null;
    }

    /**
     * Return all transaction groups.
     */
    public static function all(): array {
        $db = Database::getConnection();
        $stmt = $db->query('SELECT id, name, description, active FROM transaction_groups ORDER BY id');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['active'] = (int)$row['active'];
        }
        return $rows;
    }
}
?>
