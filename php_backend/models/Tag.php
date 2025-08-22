<?php
// Model for tag definitions and keyword-based tagging logic.
require_once __DIR__ . '/../Database.php';

class Tag {
    /**
     * Cached tag keywords to avoid repeated queries during bulk operations.
     *
     * @var array|null
     */
    private static $keywordCache = null;
    /**
     * Create a new tag optionally with a keyword for auto tagging.
     */
    public static function create(string $name, ?string $keyword = null, ?string $description = null): int {
        $db = Database::getConnection();
        $stmt = $db->prepare('INSERT INTO `tags` (`name`, `keyword`, `description`) VALUES (:name, :keyword, :description)');
        $stmt->execute(['name' => $name, 'keyword' => $keyword, 'description' => $description]);
        $id = (int)$db->lastInsertId();
        self::$keywordCache = null; // clear cache so new tag is recognised
        return $id;
    }

    /**
     * Retrieve all tags with their IDs, names, keywords and descriptions.
     */
    public static function all(): array {
        $db = Database::getConnection();
        $stmt = $db->query('SELECT `id`, `name`, `keyword`, `description` FROM `tags`');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve tags that are not assigned to any category.
     */
    public static function unassigned(): array {
        $db = Database::getConnection();
        $sql = 'SELECT t.id, t.name, t.keyword, t.description '
             . 'FROM tags t '
             . 'LEFT JOIN category_tags ct ON t.id = ct.tag_id '
             . 'WHERE ct.tag_id IS NULL';
        $stmt = $db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update a tag's name, keyword and description.
     */
    public static function update(int $id, string $name, ?string $keyword = null, ?string $description = null): bool {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE `tags` SET `name` = :name, `keyword` = :keyword, `description` = :description WHERE `id` = :id');
        $result = $stmt->execute(['name' => $name, 'keyword' => $keyword, 'description' => $description, 'id' => $id]);
        self::$keywordCache = null; // keyword may have changed
        return $result;
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
        $result = $stmt->execute(['id' => $id]);
        self::$keywordCache = null; // tag removed
        return $result;
    }

    /**
     * Clear tag references from all transactions.
     * Returns the number of rows affected.
     */
    public static function clearFromTransactions(): int {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE `transactions` SET `tag_id` = NULL WHERE `tag_id` IS NOT NULL');
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Find a tag whose keyword appears in the provided text.
     */
    public static function findMatch(string $text): ?int {
        if (self::$keywordCache === null) {
            $db = Database::getConnection();
            $stmt = $db->query('SELECT `id`, `keyword` FROM `tags` WHERE `keyword` IS NOT NULL AND `keyword` != ""');
            self::$keywordCache = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        foreach (self::$keywordCache as $row) {
            if (stripos($text, $row['keyword']) !== false) {
                return (int)$row['id'];
            }
        }
        return null;
    }

    /**
     * Look up a tag's id by its exact name.
     */
    public static function getIdByName(string $name): ?int {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT `id` FROM `tags` WHERE `name` = :name LIMIT 1');
        $stmt->execute(['name' => $name]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    /**
     * Return the id for the IGNORE tag, creating it if missing.
     */
    public static function getIgnoreId(): int {
        $id = self::getIdByName('IGNORE');
        if ($id === null) {
            $id = self::create('IGNORE', 'IGNORE', 'Ignored transactions');
        }
        return $id;
    }

    /**
     * Return the id for the interest charge tag, creating it if missing.
     */
    public static function getInterestChargeId(): int {
        $id = self::getIdByName('interest charge');
        if ($id === null) {
            $id = self::create('interest charge', null, 'Interest charges');
        }
        return $id;
    }

    /**
     * Set a tag's keyword if it is currently blank.
     */
    public static function setKeywordIfMissing(int $tagId, string $keyword): void {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE `tags` SET `keyword` = :kw WHERE `id` = :id AND (`keyword` IS NULL OR `keyword` = "")');
        $stmt->execute(['kw' => $keyword, 'id' => $tagId]);
        self::$keywordCache = null;
    }

    /**
     * Forcefully set a tag's keyword, overwriting any existing value.
     */
    public static function setKeyword(int $tagId, string $keyword): void {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE `tags` SET `keyword` = :kw WHERE `id` = :id');
        $stmt->execute(['kw' => $keyword, 'id' => $tagId]);
        self::$keywordCache = null;
    }

    /**
     * Set a tag's description if it is currently blank.
     */
    public static function setDescriptionIfMissing(int $tagId, string $description): void {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE `tags` SET `description` = :descr WHERE `id` = :id AND (`description` IS NULL OR `description` = "")');
        $stmt->execute(['descr' => $description, 'id' => $tagId]);
    }

    /**
     * Forcefully set a tag's description, overwriting any existing value.
     */
    public static function setDescription(int $tagId, string $description): void {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE `tags` SET `description` = :descr WHERE `id` = :id');
        $stmt->execute(['descr' => $description, 'id' => $tagId]);
    }

    /**
     * Apply tag keywords to untagged transactions for a given account.
     * Returns the number of transactions updated.
     */
    public static function applyToAccountTransactions(int $accountId): int {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT `id`, `description`, `ofx_type` FROM `transactions` WHERE `account_id` = :acc AND `tag_id` IS NULL');
        $stmt->execute(['acc' => $accountId]);
        $updated = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $tx) {
            $tagId = self::findMatch($tx['description']);
            if ($tagId === null && $tx['ofx_type'] === 'INT') {
                $tagId = self::getInterestChargeId();
            }
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
