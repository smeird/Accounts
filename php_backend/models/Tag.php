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
    public static function create(string $name, ?string $keyword = null, ?string $description = null, string $origin = 'manual'): int {
        $normalizedName = self::normalizeName($name);
        if ($normalizedName === '') {
            throw new InvalidArgumentException('Tag name must not be empty');
        }
        $origin = self::normalizeOrigin($origin);

        $existingId = self::getIdByNormalizedName($normalizedName);
        if ($existingId !== null) {
            $db = Database::getConnection();
            $reactivate = $db->prepare(
                "UPDATE tags SET status = 'active', merged_into_tag_id = NULL, origin = :origin, "
                . "keyword = CASE WHEN (keyword IS NULL OR keyword = '') THEN :keyword ELSE keyword END, "
                . "description = CASE WHEN (description IS NULL OR description = '') THEN :description ELSE description END "
                . "WHERE id = :id AND status <> 'active'"
            );
            $reactivate->execute([
                'id' => $existingId,
                'origin' => $origin,
                'keyword' => $keyword,
                'description' => $description,
            ]);
            if ($reactivate->rowCount() > 0) self::clearMatchCaches();
            return $existingId;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('INSERT INTO `tags` (`name`, `name_normalized`, `keyword`, `description`, `origin`, `status`) VALUES (:name, :name_normalized, :keyword, :description, :origin, :status)');
        try {
            $stmt->execute(['name' => $name, 'name_normalized' => $normalizedName, 'keyword' => $keyword, 'description' => $description, 'origin' => $origin, 'status' => 'active']);
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

    private static function normalizeOrigin(string $origin): string {
        return in_array($origin, ['system', 'manual', 'ai', 'legacy'], true) ? $origin : 'manual';
    }

    /**
     * Retrieve the active tag catalogue used by management controls.
     */
    public static function all(): array {
        $db = Database::getConnection();
        $stmt = $db->query("SELECT `id`, `name`, `keyword`, `description` FROM `tags` WHERE `status` = 'active' ORDER BY `name` ASC, `id` ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retire a canonical tag from future use without rewriting history.
     * Existing transaction classifications remain available to reports and
     * every matching rule is disabled atomically.
     *
     * @return array{tag_id:int,transactions_retained:int,rules_disabled:int}
     */
    public static function retire(int $id): array {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $tag = self::lockActiveTag($db, $id);
            self::assertMutableTag($tag);

            $transactions = $db->prepare('SELECT COUNT(*) FROM transactions WHERE tag_id = :id');
            $transactions->execute(['id' => $id]);
            $transactionsRetained = (int)$transactions->fetchColumn();

            $rules = $db->prepare('UPDATE tag_aliases SET active = 0 WHERE tag_id = :id AND active = 1');
            $rules->execute(['id' => $id]);
            $rulesDisabled = $rules->rowCount();

            $update = $db->prepare("UPDATE tags SET status = 'deprecated', merged_into_tag_id = NULL WHERE id = :id");
            $update->execute(['id' => $id]);
            $db->commit();
            self::clearMatchCaches();

            return [
                'tag_id' => $id,
                'transactions_retained' => $transactionsRetained,
                'rules_disabled' => $rulesDisabled,
            ];
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * Merge one active tag into another canonical tag. The destination's
     * category wins; when it is unassigned, the source category is inherited.
     * Transactions, rules and reporting classifications move together.
     *
     * @return array{source_tag_id:int,target_tag_id:int,transactions_moved:int,rules_moved:int,category_id:?int}
     */
    public static function merge(int $sourceId, int $targetId): array {
        if ($sourceId <= 0 || $targetId <= 0 || $sourceId === $targetId) {
            throw new InvalidArgumentException('Choose two different active tags to merge.');
        }

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $source = self::lockActiveTag($db, $sourceId);
            self::lockActiveTag($db, $targetId);
            self::assertMutableTag($source);

            $mapping = $db->prepare(
                'SELECT ct.category_id, c.segment_id FROM category_tags ct '
                . 'LEFT JOIN categories c ON c.id = ct.category_id WHERE ct.tag_id = :id ORDER BY ct.category_id LIMIT 1'
            );
            $mapping->execute(['id' => $targetId]);
            $targetMapping = $mapping->fetch(PDO::FETCH_ASSOC) ?: null;
            $mapping->execute(['id' => $sourceId]);
            $sourceMapping = $mapping->fetch(PDO::FETCH_ASSOC) ?: null;
            $chosenMapping = $targetMapping ?: $sourceMapping;

            if (!$targetMapping && $sourceMapping) {
                $insertMapping = $db->prepare('INSERT INTO category_tags (category_id, tag_id) VALUES (:category, :tag)');
                $insertMapping->execute(['category' => (int)$sourceMapping['category_id'], 'tag' => $targetId]);
            }

            $moveTransactions = $db->prepare(
                'UPDATE transactions SET tag_id = :target, category_id = :category, segment_id = :segment WHERE tag_id = :source'
            );
            $moveTransactions->bindValue(':target', $targetId, PDO::PARAM_INT);
            $moveTransactions->bindValue(':source', $sourceId, PDO::PARAM_INT);
            $moveTransactions->bindValue(':category', $chosenMapping ? (int)$chosenMapping['category_id'] : null, $chosenMapping ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $moveTransactions->bindValue(':segment', $chosenMapping && $chosenMapping['segment_id'] !== null ? (int)$chosenMapping['segment_id'] : null, $chosenMapping && $chosenMapping['segment_id'] !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $moveTransactions->execute();
            $transactionsMoved = $moveTransactions->rowCount();

            $moveRules = $db->prepare('UPDATE tag_aliases SET tag_id = :target WHERE tag_id = :source');
            $moveRules->execute(['target' => $targetId, 'source' => $sourceId]);
            $rulesMoved = $moveRules->rowCount();

            $removeMapping = $db->prepare('DELETE FROM category_tags WHERE tag_id = :source');
            $removeMapping->execute(['source' => $sourceId]);
            $mergeTag = $db->prepare(
                "UPDATE tags SET status = 'merged', merged_into_tag_id = :target, keyword = NULL WHERE id = :source"
            );
            $mergeTag->execute(['target' => $targetId, 'source' => $sourceId]);

            $db->commit();
            self::clearMatchCaches();
            return [
                'source_tag_id' => $sourceId,
                'target_tag_id' => $targetId,
                'transactions_moved' => $transactionsMoved,
                'rules_moved' => $rulesMoved,
                'category_id' => $chosenMapping ? (int)$chosenMapping['category_id'] : null,
            ];
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    private static function lockActiveTag(PDO $db, int $id): array {
        $suffix = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $stmt = $db->prepare("SELECT id, name, name_normalized, origin, status FROM tags WHERE id = :id AND status = 'active'" . $suffix);
        $stmt->execute(['id' => $id]);
        $tag = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tag) throw new InvalidArgumentException('The selected active tag could not be found.');
        return $tag;
    }

    private static function assertMutableTag(array $tag): void {
        $normalized = self::normalizeName((string)($tag['name'] ?? ''));
        if ($normalized === 'ignore' || ($tag['origin'] ?? '') === 'system') {
            throw new InvalidArgumentException('Protected system tags cannot be retired or merged.');
        }
    }

    /**
     * Return compact tag choices for autocomplete controls.
     *
     * Results beginning with the query are ranked ahead of other contains
     * matches. The bounded response avoids sending every tag to controls that
     * only need a short list of relevant choices.
     */
    public static function searchOptions(string $query = '', int $limit = 20): array {
        $db = Database::getConnection();
        $query = trim($query);
        $limit = max(1, min(100, $limit));
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query);
        $queryEmpty = $query === '' ? 1 : 0;

        $sql = 'SELECT `id`, `name` FROM `tags` '
             . "WHERE `status` = 'active' AND (:query_empty = 1 OR `name` LIKE :contains ESCAPE '!') "
             . 'ORDER BY CASE WHEN :prefix_empty = 0 AND `name` LIKE :prefix ESCAPE \'!\' THEN 0 ELSE 1 END, '
             . '`name` ASC, `id` ASC LIMIT :result_limit';
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':query_empty', $queryEmpty, PDO::PARAM_INT);
        $stmt->bindValue(':contains', '%' . $escaped . '%', PDO::PARAM_STR);
        $stmt->bindValue(':prefix_empty', $queryEmpty, PDO::PARAM_INT);
        $stmt->bindValue(':prefix', $escaped . '%', PDO::PARAM_STR);
        $stmt->bindValue(':result_limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
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
             . "WHERE ct.tag_id IS NULL AND t.status = 'active'";
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
    public static function findMatch(string $text, ?float $amount = null, ?array &$match = null): ?int {
        $match = null;
        if (self::$aliasCache === null) {
            self::$aliasCache = TagAlias::activeMappings();
        }

        $normalizedText = self::normalizeMatchPhrase($text);
        $transactionDirection = $amount === null || abs($amount) < 0.00001 ? 'any' : ($amount < 0 ? 'outgoing' : 'incoming');
        foreach (self::$aliasCache as $row) {
            $aliasDirection = TagAlias::normalizeDirection((string)($row['direction'] ?? 'any'));
            if ($aliasDirection !== 'any' && $aliasDirection !== $transactionDirection) {
                continue;
            }
            $normalizedAlias = self::normalizeMatchPhrase((string)$row['alias']);
            if ($normalizedAlias === '') {
                continue;
            }
            if ($row['match_type'] === 'exact' && $normalizedText === $normalizedAlias) {
                $match = ['source' => 'alias', 'alias_id' => (int)$row['id']];
                return (int)$row['tag_id'];
            }
            if ($row['match_type'] !== 'exact' && strpos(' ' . $normalizedText . ' ', ' ' . $normalizedAlias . ' ') !== false) {
                $match = ['source' => 'alias', 'alias_id' => (int)$row['id']];
                return (int)$row['tag_id'];
            }
        }

        if (self::$keywordCache === null) {
            $db = Database::getConnection();
            $stmt = $db->query("SELECT `id`, `keyword` FROM `tags` WHERE `status` = 'active' AND `keyword` IS NOT NULL AND `keyword` != ''");
            self::$keywordCache = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        foreach (self::$keywordCache as $row) {
            if (stripos($text, $row['keyword']) !== false) {
                $match = ['source' => 'keyword', 'alias_id' => null];
                return (int)$row['id'];
            }
        }
        return null;
    }

    /**
     * Learn a conservative merchant alias from a transaction that a person or AI
     * has assigned to a canonical tag. Existing aliases are never reassigned to a
     * different tag: a conflict is returned for review instead.
     *
     * @return array{status:string,alias:?string,tag_id:int,existing_tag_id:?int,overlaps?:array}
     */
    public static function learnTransactionAlias(int $tagId, string $description, ?string $memo = null, string $origin = 'manual', ?float $amount = null): array {
        $alias = self::buildReusableAlias($description, $memo);
        $result = [
            'status' => 'filtered',
            'alias' => $alias,
            'tag_id' => $tagId,
            'existing_tag_id' => null,
        ];
        if ($alias === null) {
            return $result;
        }

        $db = Database::getConnection();
        $normalized = TagAlias::normalizeAlias($alias);
        $direction = $amount === null || abs($amount) < 0.00001 ? 'any' : ($amount < 0 ? 'outgoing' : 'incoming');
        $stmt = $db->prepare('SELECT `id`, `tag_id` FROM `tag_aliases` WHERE `alias_normalized` = :alias AND `direction` = :direction LIMIT 1');
        $stmt->execute(['alias' => $normalized, 'direction' => $direction]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $existingTagId = (int)$existing['tag_id'];
            $result['existing_tag_id'] = $existingTagId;
            if ($existingTagId !== $tagId) {
                $result['status'] = 'conflict';
                return $result;
            }

            $activate = $db->prepare('UPDATE `tag_aliases` SET `active` = 1, `match_type` = :match_type WHERE `id` = :id');
            $activate->execute(['match_type' => 'contains', 'id' => (int)$existing['id']]);
            self::clearMatchCaches();
            $result['status'] = 'existing';
            return $result;
        }

        try {
            $overlaps = TagAlias::overlapWarnings($alias, $tagId, $direction);
            if (!empty($overlaps)) {
                $result['status'] = 'overlap';
                $result['overlaps'] = $overlaps;
                return $result;
            }
            TagAlias::create($tagId, $alias, 'contains', true, self::normalizeOrigin($origin), null, 1, $direction);
            self::clearMatchCaches();
            $result['status'] = 'created';
            return $result;
        } catch (PDOException $e) {
            // A concurrent request may have inserted the alias after our lookup.
            $stmt->execute(['alias' => $normalized, 'direction' => $direction]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $existingTagId = (int)$existing['tag_id'];
                $result['existing_tag_id'] = $existingTagId;
                $result['status'] = $existingTagId === $tagId ? 'existing' : 'conflict';
                self::clearMatchCaches();
                return $result;
            }
            throw $e;
        }
    }

    /**
     * Derive a stable first merchant token while removing bank boilerplate,
     * dates, card suffixes and reference numbers that change per transaction.
     */
    private static function buildReusableAlias(string $description, ?string $memo = null): ?string {
        $text = trim($description . ' ' . (string)$memo);
        $normalized = self::normalizeMatchPhrase($text);
        if ($normalized === '') {
            return null;
        }

        $generic = array_fill_keys([
            'a', 'account', 'an', 'at', 'bank', 'banking', 'bacs', 'bill', 'card',
            'cash', 'charge', 'contactless', 'credit', 'debit', 'deposit', 'direct',
            'faster', 'from', 'klarna', 'mobile', 'monthly', 'online', 'order', 'payment',
            'payments', 'paypal', 'pending', 'pos', 'purchase', 'receipt', 'received',
            'recurring', 'ref', 'reference', 'refund', 'sent', 'square', 'standing',
            'stripe', 'sumup', 'the', 'to', 'transaction', 'transfer', 'xfer', 'zettle'
        ], true);
        foreach (explode(' ', $normalized) as $token) {
            if ($token === '' || isset($generic[$token]) || preg_match('/\d/', $token)) {
                continue;
            }
            if (strlen($token) < 4 || strlen($token) > 60 || !preg_match('/\p{L}/u', $token)) {
                continue;
            }
            return $token;
        }
        return null;
    }

    /**
     * Normalize free-form bank text for whole-token alias matching.
     */
    private static function normalizeMatchPhrase(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (function_exists('mb_strtolower')) {
            $value = mb_strtolower($value, 'UTF-8');
        } else {
            $value = strtolower($value);
        }
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Combine description and memo text so alias/keyword matching can use both.
     */
    public static function buildMatchText(string $description, ?string $memo = null): string {
        $description = trim($description);
        $memo = $memo !== null ? trim($memo) : '';
        if ($memo === '') {
            return $description;
        }
        return $description . ' ' . $memo;
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
     * Look up only a currently selectable canonical tag by exact name.
     */
    public static function getActiveIdByName(string $name): ?int {
        $normalizedName = self::normalizeName($name);
        if ($normalizedName === '') return null;
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id FROM tags WHERE name_normalized = :name AND status = 'active' LIMIT 1");
        $stmt->execute(['name' => $normalizedName]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
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
        $id = self::getActiveIdByName('IGNORE');
        if ($id === null) {
            $id = self::create('IGNORE', 'IGNORE', 'Ignored transactions', 'system');
        }
        return $id;
    }

    /**
     * Return the id for the interest charge tag, creating it if missing.
     */
    public static function getInterestChargeId(): int {
        $db = Database::getConnection();
        $stmt = $db->query(
            "SELECT id FROM tags WHERE status = 'active' AND name_normalized IN ('interest charges','interest charge') "
            . "ORDER BY CASE WHEN name_normalized = 'interest charges' THEN 0 ELSE 1 END, id LIMIT 1"
        );
        $id = $stmt->fetchColumn();
        if ($id !== false) return (int)$id;

        // Prefer the reviewed canonical plural. If it already exists but was
        // previously deprecated, reactivate that exact record rather than
        // falling back to a retired singular legacy tag.
        $id = self::getIdByName('Interest Charges');
        if ($id === null) {
            $id = self::create('Interest Charges', null, 'Interest charges', 'system');
        } else {
            $activate = $db->prepare("UPDATE tags SET status = 'active', merged_into_tag_id = NULL WHERE id = :id");
            $activate->execute(['id' => $id]);
            self::clearMatchCaches();
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
        $stmt = $db->prepare('SELECT `id`, `description`, `memo`, `amount`, `ofx_type` FROM `transactions` WHERE `account_id` = :acc AND `tag_id` IS NULL AND `transfer_id` IS NULL');
        $stmt->execute(['acc' => $accountId]);
        $upd = $db->prepare('UPDATE `transactions` SET `tag_id` = :tag WHERE `id` = :id');
        $updated = 0;
        $aliasMatches = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $tx) {
            $match = null;
            $tagId = self::findMatch(self::buildMatchText($tx['description'], $tx['memo']), (float)$tx['amount'], $match);
            if ($tagId === null && $tx['ofx_type'] === 'INT') {
                $tagId = self::getInterestChargeId();
            }
            if ($tagId !== null) {
                $upd->execute(['tag' => $tagId, 'id' => $tx['id']]);
                $updated++;
                if (($match['source'] ?? null) === 'alias' && !empty($match['alias_id'])) {
                    $aliasId = (int)$match['alias_id'];
                    $aliasMatches[$aliasId] = ($aliasMatches[$aliasId] ?? 0) + 1;
                }
            }
        }
        TagAlias::recordMatches($aliasMatches);
        return $updated;
    }

    /**
     * Apply tag keywords to transactions across all accounts.
     * Returns the total number of transactions updated.
     */
    public static function applyToAllTransactions(): int {
        $db = Database::getConnection();
        $accountIds = $db->query('SELECT DISTINCT `account_id` FROM `transactions`')->fetchAll(PDO::FETCH_COLUMN);
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
        $stmt = $db->query('SELECT `id`, `description`, `memo`, `amount`, `ofx_type`, `tag_id` FROM `transactions` WHERE `transfer_id` IS NULL');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $moves = [];
        $updated = 0;
        $tagNames = self::getTagNamesById();

        foreach ($rows as $tx) {
            $currentTagId = $tx['tag_id'] !== null ? (int)$tx['tag_id'] : null;
            $newTagId = self::findMatch(self::buildMatchText($tx['description'], $tx['memo']), (float)$tx['amount']);
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
