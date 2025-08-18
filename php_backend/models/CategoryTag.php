<?php
// Links categories and tags and applies categories based on tag matches.
require_once __DIR__ . '/../Database.php';

class CategoryTag {
    /**
     * Link a tag to a category, ensuring it isn't already assigned.
     */
    public static function add(int $categoryId, int $tagId): void {
        $db = Database::getConnection();
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
        $sql = 'UPDATE transactions t '
             . 'LEFT JOIN category_tags ct ON t.tag_id = ct.tag_id '
             . 'SET t.category_id = ct.category_id '
             . 'WHERE t.account_id = :acc '
             . 'AND t.tag_id IS NOT NULL '
             . 'AND NOT (t.category_id <=> ct.category_id)';
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
        $accountIds = $db->query('SELECT `id` FROM `accounts`')->fetchAll(PDO::FETCH_COLUMN);
        $total = 0;
        foreach ($accountIds as $accId) {
            $total += self::applyToAccountTransactions((int)$accId);
        }
        return $total;
    }
}
?>
