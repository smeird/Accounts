<?php
require_once __DIR__ . '/../Database.php';

class Tag {
    public static function create(string $name, ?string $keyword = null): int {
        $db = Database::getConnection();
        $stmt = $db->prepare('INSERT INTO `tags` (`name`, `keyword`) VALUES (:name, :keyword)');
        $stmt->execute(['name' => $name, 'keyword' => $keyword]);
        return (int)$db->lastInsertId();
    }

    public static function all(): array {
        $db = Database::getConnection();
        $stmt = $db->query('SELECT `id`, `name`, `keyword` FROM `tags`');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function update(int $id, string $name, ?string $keyword = null): bool {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE `tags` SET `name` = :name, `keyword` = :keyword WHERE `id` = :id');
        return $stmt->execute(['name' => $name, 'keyword' => $keyword, 'id' => $id]);
    }

    public static function delete(int $id): bool {
        $db = Database::getConnection();
        // remove any relationships to categories
        $stmt = $db->prepare('DELETE FROM `category_tags` WHERE `tag_id` = :id');
        $stmt->execute(['id' => $id]);

        // clear references from transactions
        $stmt = $db->prepare('UPDATE `transactions` SET `tag_id` = NULL WHERE `tag_id` = :id');
        $stmt->execute(['id' => $id]);

        // delete the tag itself
        $stmt = $db->prepare('DELETE FROM `tags` WHERE `id` = :id');
        return $stmt->execute(['id' => $id]);
    }

    public static function findMatch(string $text): ?int {
        $db = Database::getConnection();
        $stmt = $db->query('SELECT `id`, `keyword` FROM `tags` WHERE `keyword` IS NOT NULL AND `keyword` != ""');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (stripos($text, $row['keyword']) !== false) {
                return (int)$row['id'];
            }
        }
        return null;
    }

    /**
     * Apply tag keywords to untagged transactions for a given account.
     * Returns the number of transactions updated.
     */
    public static function applyToAccountTransactions(int $accountId): int {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT `id`, `description` FROM `transactions` WHERE `account_id` = :acc AND `tag_id` IS NULL');
        $stmt->execute(['acc' => $accountId]);
        $updated = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $tx) {
            $tagId = self::findMatch($tx['description']);
            if ($tagId !== null) {
                $upd = $db->prepare('UPDATE `transactions` SET `tag_id` = :tag WHERE `id` = :id');
                $upd->execute(['tag' => $tagId, 'id' => $tx['id']]);
                $updated++;
            }
        }
        return $updated;
    }
}
?>
