<?php
// Model for tag definitions and keyword-based tagging logic.
require_once __DIR__ . '/../Database.php';

class Tag {
    /**
     * Create a new tag optionally with a keyword for auto tagging.
     */
    public static function create(string $name, ?string $keyword = null): int {
        $db = Database::getConnection();
        $stmt = $db->prepare('INSERT INTO `tags` (`name`, `keyword`) VALUES (:name, :keyword)');
        $stmt->execute(['name' => $name, 'keyword' => $keyword]);
        return (int)$db->lastInsertId();
    }

    /**
     * Retrieve all tags with their IDs, names and keywords.
     */
    public static function all(): array {
        $db = Database::getConnection();
        $stmt = $db->query('SELECT `id`, `name`, `keyword` FROM `tags`');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve tags that are not assigned to any category.
     */
    public static function unassigned(): array {
        $db = Database::getConnection();
        $sql = 'SELECT t.id, t.name, t.keyword '
             . 'FROM tags t '
             . 'LEFT JOIN category_tags ct ON t.id = ct.tag_id '
             . 'WHERE ct.tag_id IS NULL';
        $stmt = $db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update a tag's name and keyword.
     */
    public static function update(int $id, string $name, ?string $keyword = null): bool {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE `tags` SET `name` = :name, `keyword` = :keyword WHERE `id` = :id');
        return $stmt->execute(['name' => $name, 'keyword' => $keyword, 'id' => $id]);
    }

    /**
     * Remove a tag and any references to it.
     */
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

    /**
     * Find a tag whose keyword appears in the provided text.
     */
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
     * Set a tag's keyword if it is currently blank.
     */
    public static function setKeywordIfMissing(int $tagId, string $keyword): void {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE `tags` SET `keyword` = :kw WHERE `id` = :id AND (`keyword` IS NULL OR `keyword` = "")');
        $stmt->execute(['kw' => $keyword, 'id' => $tagId]);
    }

    /**
     * Forcefully set a tag's keyword, overwriting any existing value.
     */
    public static function setKeyword(int $tagId, string $keyword): void {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE `tags` SET `keyword` = :kw WHERE `id` = :id');
        $stmt->execute(['kw' => $keyword, 'id' => $tagId]);
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

    /**
     * Apply tag keywords to transactions across all accounts.
     * Returns the total number of transactions updated.
     */
    public static function applyToAllTransactions(): int {
        $db = Database::getConnection();
        $accountIds = $db->query('SELECT `id` FROM `accounts`')->fetchAll(PDO::FETCH_COLUMN);
        $total = 0;
        foreach ($accountIds as $accId) {
            $total += self::applyToAccountTransactions((int)$accId);
        }
        return $total;
    }
}
?>
