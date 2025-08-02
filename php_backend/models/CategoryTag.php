<?php
require_once __DIR__ . '/../Database.php';

class CategoryTag {
    public static function add(int $categoryId, int $tagId): void {
        $db = Database::getConnection();
        $stmt = $db->prepare('INSERT IGNORE INTO category_tags (category_id, tag_id) VALUES (:category_id, :tag_id)');
        $stmt->execute(['category_id' => $categoryId, 'tag_id' => $tagId]);
    }

    public static function remove(int $categoryId, int $tagId): void {
        $db = Database::getConnection();
        $stmt = $db->prepare('DELETE FROM category_tags WHERE category_id = :category_id AND tag_id = :tag_id');
        $stmt->execute(['category_id' => $categoryId, 'tag_id' => $tagId]);
    }

    /**
     * Apply category IDs to transactions for a specific account based on their tag.
     * Only transactions that are tagged and currently uncategorised will be updated.
     * Returns the number of transactions that were categorised.
     */
    public static function applyToAccountTransactions(int $accountId): int {
        $db = Database::getConnection();
        $sql = 'UPDATE transactions t '
             . 'JOIN category_tags ct ON t.tag_id = ct.tag_id '
             . 'SET t.category_id = ct.category_id '
             . 'WHERE t.account_id = :acc '
             . 'AND t.tag_id IS NOT NULL '
             . 'AND t.category_id IS NULL';
        $stmt = $db->prepare($sql);
        $stmt->execute(['acc' => $accountId]);
        return $stmt->rowCount();
    }
}
?>
