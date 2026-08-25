<?php
// Links categories and tags and applies categories based on tag matches.
require_once __DIR__ . '/../Database.php';

class CategoryTag {
    /**
     * Set the one category implied by a tag, or clear the assignment with null.
     * The mapping and existing tagged transactions are updated atomically.
     *
     * @return array{previous_category_id:?int,category_id:?int,updated_transactions:int}
     */
    public static function assign(?int $categoryId, int $tagId): array {
        $batch = self::assignMany($categoryId, [$tagId]);
        $assignment = $batch['assignments'][0];
        return [
            'previous_category_id' => $assignment['previous_category_id'],
            'category_id' => $batch['category_id'],
            'updated_transactions' => $batch['updated_transactions'],
        ];
    }

    /**
     * Assign several tags to one category in a single transaction.
     *
     * @return array{tag_ids:array,category_id:?int,updated_transactions:int,assignments:array}
     */
    public static function assignMany(?int $categoryId, array $tagIds): array {
        $tagIds = array_values(array_unique(array_filter(array_map('intval', $tagIds), function ($tagId) {
            return $tagId > 0;
        })));
        if (empty($tagIds) || ($categoryId !== null && $categoryId <= 0)) {
            throw new InvalidArgumentException('Valid tag and category IDs are required');
        }

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            if ($categoryId !== null) {
                $categoryCheck = $db->prepare('SELECT `id` FROM `categories` WHERE `id` = :id LIMIT 1');
                $categoryCheck->execute(['id' => $categoryId]);
                if (!$categoryCheck->fetchColumn()) {
                    throw new InvalidArgumentException('Category not found');
                }
            }

            $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
            $tagCheck = $db->prepare("SELECT `id` FROM `tags` WHERE `status` = 'active' AND `id` IN ($placeholders)");
            $tagCheck->execute($tagIds);
            $existingTagIds = array_map('intval', $tagCheck->fetchAll(PDO::FETCH_COLUMN));
            sort($existingTagIds);
            $requestedTagIds = $tagIds;
            sort($requestedTagIds);
            if ($existingTagIds !== $requestedTagIds) {
                throw new InvalidArgumentException('One or more tags were not found');
            }

            $current = $db->prepare("SELECT `tag_id`, `category_id` FROM `category_tags` WHERE `tag_id` IN ($placeholders)");
            $current->execute($tagIds);
            $previousByTag = [];
            foreach ($current->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $previousByTag[(int)$row['tag_id']] = (int)$row['category_id'];
            }

            $remove = $db->prepare("DELETE FROM `category_tags` WHERE `tag_id` IN ($placeholders)");
            $remove->execute($tagIds);

            if ($categoryId !== null) {
                $insert = $db->prepare('INSERT INTO `category_tags` (`category_id`, `tag_id`) VALUES (:category, :tag)');
                foreach ($tagIds as $tagId) {
                    $insert->execute(['category' => $categoryId, 'tag' => $tagId]);
                }
            }

            $update = $db->prepare("UPDATE `transactions` SET `category_id` = ? WHERE `tag_id` IN ($placeholders) AND `transfer_id` IS NULL");
            $update->execute(array_merge([$categoryId], $tagIds));
            $updatedTransactions = $update->rowCount();

            $assignments = [];
            foreach ($tagIds as $tagId) {
                $assignments[] = [
                    'tag_id' => $tagId,
                    'previous_category_id' => $previousByTag[$tagId] ?? null,
                ];
            }

            $db->commit();
            return [
                'tag_ids' => $tagIds,
                'category_id' => $categoryId,
                'updated_transactions' => $updatedTransactions,
                'assignments' => $assignments,
            ];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Link a tag to a category, ensuring it isn't already assigned.
     */
    public static function add(int $categoryId, int $tagId): void {
        $db = Database::getConnection();
        $activeTag = $db->prepare("SELECT 1 FROM tags WHERE id = :tag_id AND status = 'active'");
        $activeTag->execute(['tag_id' => $tagId]);
        if (!$activeTag->fetchColumn()) {
            throw new InvalidArgumentException('Only active tags can be assigned to a category');
        }
        $check = $db->prepare('SELECT 1 FROM category_tags WHERE tag_id = :tag_id');
        $check->execute(['tag_id' => $tagId]);
        if ($check->fetch()) {
            throw new Exception('Tag is already assigned to a category');
        }
        $stmt = $db->prepare('INSERT INTO category_tags (category_id, tag_id) VALUES (:category_id, :tag_id)');
        $stmt->execute(['category_id' => $categoryId, 'tag_id' => $tagId]);
    }

    /**
     * Remove the association between a category and a tag.
     */
    public static function remove(int $categoryId, int $tagId): void {
        $db = Database::getConnection();
        $stmt = $db->prepare('DELETE FROM category_tags WHERE category_id = :category_id AND tag_id = :tag_id');
        $stmt->execute(['category_id' => $categoryId, 'tag_id' => $tagId]);
    }

    /**
     * Return the category id currently linked to the given tag, or null if unassigned.
     */
    public static function getCategoryId(int $tagId): ?int {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT category_id FROM category_tags WHERE tag_id = :tag LIMIT 1');
        $stmt->execute(['tag' => $tagId]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    /**
     * Move a tag from one category to another atomically.
     */
    public static function move(int $oldCategoryId, int $newCategoryId, int $tagId): void {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $del = $db->prepare('DELETE FROM category_tags WHERE category_id = :old AND tag_id = :tag');
            $del->execute(['old' => $oldCategoryId, 'tag' => $tagId]);

            $ins = $db->prepare('INSERT INTO category_tags (category_id, tag_id) VALUES (:new, :tag)');
            $ins->execute(['new' => $newCategoryId, 'tag' => $tagId]);

            $upd = $db->prepare('UPDATE transactions SET category_id = :new WHERE tag_id = :tag AND category_id = :old');
            $upd->execute(['new' => $newCategoryId, 'tag' => $tagId, 'old' => $oldCategoryId]);

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Clear category assignments from all transactions.
     * Returns the number of rows affected.
     */
    public static function clearFromTransactions(): int {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE transactions SET category_id = NULL WHERE category_id IS NOT NULL');
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Apply category IDs to transactions for a specific account based on their tag.
     * Transactions are updated whenever their tag implies a different category,
     * ensuring changes in tagging are reflected in categorisation.
     * Returns the number of transactions that were categorised.
     */
    public static function applyToAccountTransactions(int $accountId): int {
        $db = Database::getConnection();
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql = 'UPDATE transactions t '
                 . 'LEFT JOIN category_tags ct ON t.tag_id = ct.tag_id '
                 . 'SET t.category_id = ct.category_id '
                 . 'WHERE t.account_id = :acc '
                 . 'AND t.tag_id IS NOT NULL '
                 . 'AND t.transfer_id IS NULL '
                 . 'AND NOT (t.category_id <=> ct.category_id)';
        } else {
            $categoryLookup = '(SELECT ct.category_id FROM category_tags ct '
                . 'WHERE ct.tag_id = transactions.tag_id ORDER BY ct.category_id LIMIT 1)';
            $sql = 'UPDATE transactions SET category_id = ' . $categoryLookup . ' '
                 . 'WHERE account_id = :acc AND tag_id IS NOT NULL AND transfer_id IS NULL '
                 . 'AND COALESCE(category_id, -1) != COALESCE(' . $categoryLookup . ', -1)';
        }
        $stmt = $db->prepare($sql);
        $stmt->execute(['acc' => $accountId]);
        return $stmt->rowCount();
    }

    /**
     * Apply categories to transactions across all accounts based on their tag.
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
}
?>
