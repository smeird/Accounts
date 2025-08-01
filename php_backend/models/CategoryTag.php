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
}
?>
