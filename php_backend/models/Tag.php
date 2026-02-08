<?php
// Model for tag definitions and keyword-based tagging logic.
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/TagAlias.php';

class Tag {
    /**
     * Cached tag keywords to avoid repeated queries during bulk operations.
     *
     * @var array|null
     */
    private static $keywordCache = null;

    /**
     * Cached active aliases to evaluate before keywords.
     *
     * @var array|null
     */
    private static $aliasCache = null;
    /**
     * Reset cached keywords and aliases.
     */
    public static function clearMatchCaches(): void {
        self::$keywordCache = null;
        self::$aliasCache = null;
    }

    /**
     * Create a new tag optionally with a keyword for auto tagging.
     */
    public static function create(string $name, ?string $keyword = null, ?string $description = null): int {
        $normalizedName = self::normalizeName($name);
        if ($normalizedName === '') {
            throw new InvalidArgumentException('Tag name must not be empty');
        }

        $existingId = self::getIdByNormalizedName($normalizedName);
        if ($existingId !== null) {
            return $existingId;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('INSERT INTO `tags` (`name`, `name_normalized`, `keyword`, `description`) VALUES (:name, :name_normalized, :keyword, :description)');
        try {
            $stmt->execute(['name' => $name, 'name_normalized' => $normalizedName, 'keyword' => $keyword, 'description' => $description]);
        } catch (PDOException $e) {
            $existingId = self::getIdByNormalizedName($normalizedName);
            if ($existingId !== null) {
                return $existingId;
            }
            throw $e;
        }
        $id = (int)$db->lastInsertId();
        self::clearMatchCaches();
        return $id;
    }

    /**
     * Normalize a tag name by trimming, lowercasing, and collapsing whitespace.
     */
    public static function normalizeName(string $name): string {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return '';
        }
        return strtolower(preg_replace('/\s+/', ' ', $trimmed));
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
        $normalizedName = self::normalizeName($name);
        if ($normalizedName === '') {
            throw new InvalidArgumentException('Tag name must not be empty');
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE `tags` SET `name` = :name, `name_normalized` = :name_normalized, `keyword` = :keyword, `description` = :description WHERE `id` = :id');
        $result = $stmt->execute(['name' => $name, 'name_normalized' => $normalizedName, 'keyword' => $keyword, 'description' => $description, 'id' => $id]);
        self::clearMatchCaches();
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
        self::clearMatchCaches();
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
        if (self::$aliasCache === null) {
            self::$aliasCache = TagAlias::activeMappings();
        }

        $normalizedText = strtolower(trim($text));
        foreach (self::$aliasCache as $row) {
            if ($row['match_type'] === 'exact' && $normalizedText === $row['alias_normalized']) {
                return (int)$row['tag_id'];
            }
            if ($row['match_type'] !== 'exact' && stripos($text, $row['alias']) !== false) {
                return (int)$row['tag_id'];
            }
        }

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
        $normalizedName = self::normalizeName($name);
        if ($normalizedName === '') {
            return null;
        }
        return self::getIdByNormalizedName($normalizedName);
    }

    /**
     * Look up a tag's id by normalized name.
     */
    public static function getIdByNormalizedName(string $normalizedName): ?int {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT `id` FROM `tags` WHERE `name_normalized` = :name_normalized LIMIT 1');
        $stmt->execute(['name_normalized' => $normalizedName]);
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
        self::clearMatchCaches();
    }

    /**
     * Forcefully set a tag's keyword, overwriting any existing value.
     */
    public static function setKeyword(int $tagId, string $keyword): void {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE `tags` SET `keyword` = :kw WHERE `id` = :id');
        $stmt->execute(['kw' => $keyword, 'id' => $tagId]);
        self::clearMatchCaches();
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

    /**
     * Re-evaluate all transactions against current tag keywords and return remap counts.
     *
     * @param bool $applyChanges When true, transaction tag_id values are updated.
     * @return array{updated:int,moves:array<int,array<string,mixed>>}
     */
    public static function remapAllTransactionsToCanonicalTags(bool $applyChanges = false): array {
        $db = Database::getConnection();
        $stmt = $db->query('SELECT `id`, `description`, `ofx_type`, `tag_id` FROM `transactions`');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $moves = [];
        $updated = 0;
        $tagNames = self::getTagNamesById();

        foreach ($rows as $tx) {
            $currentTagId = $tx['tag_id'] !== null ? (int)$tx['tag_id'] : null;
            $newTagId = self::findMatch($tx['description']);
            if ($newTagId === null && $tx['ofx_type'] === 'INT') {
                $newTagId = self::getInterestChargeId();
            }

            if ($newTagId === null || $currentTagId === $newTagId) {
                continue;
            }

            $fromLabel = $currentTagId !== null && isset($tagNames[$currentTagId])
                ? $tagNames[$currentTagId]
                : 'Not Tagged';
            $toLabel = isset($tagNames[$newTagId])
                ? $tagNames[$newTagId]
                : ('Tag #' . $newTagId);
            $key = ($currentTagId !== null ? (string)$currentTagId : 'null') . '->' . (string)$newTagId;

            if (!isset($moves[$key])) {
                $moves[$key] = [
                    'from_tag_id' => $currentTagId,
                    'from_tag_name' => $fromLabel,
                    'to_tag_id' => $newTagId,
                    'to_tag_name' => $toLabel,
                    'count' => 0,
                ];
            }
            $moves[$key]['count']++;

            if ($applyChanges) {
                $upd = $db->prepare('UPDATE `transactions` SET `tag_id` = :tag WHERE `id` = :id');
                $upd->execute(['tag' => $newTagId, 'id' => (int)$tx['id']]);
                $updated += $upd->rowCount();
            }
        }

        return ['updated' => $updated, 'moves' => array_values($moves)];
    }

    /**
     * Fetch tag names indexed by tag id.
     *
     * @return array<int,string>
     */
    private static function getTagNamesById(): array {
        $db = Database::getConnection();
        $stmt = $db->query('SELECT `id`, `name` FROM `tags`');
        $names = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $names[(int)$row['id']] = $row['name'];
        }
        return $names;
    }
}
?>
