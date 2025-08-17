<?php
// Links segments and categories ensuring each category belongs to at most one segment.
require_once __DIR__ . '/../Database.php';

class SegmentCategory {
    /**
     * Link a category to a segment, ensuring it isn't already assigned.
     */
    public static function add(int $segmentId, int $categoryId): void {
        $db = Database::getConnection();
        $check = $db->prepare('SELECT 1 FROM segment_categories WHERE category_id = :category');
        $check->execute(['category' => $categoryId]);
        if ($check->fetch()) {
            throw new Exception('Category is already assigned to a segment');
        }
        $stmt = $db->prepare('INSERT INTO segment_categories (segment_id, category_id) VALUES (:segment, :category)');
        $stmt->execute(['segment' => $segmentId, 'category' => $categoryId]);
    }

    /**
     * Remove the association between a segment and a category.
     */
    public static function remove(int $segmentId, int $categoryId): void {
        $db = Database::getConnection();
        $stmt = $db->prepare('DELETE FROM segment_categories WHERE segment_id = :segment AND category_id = :category');
        $stmt->execute(['segment' => $segmentId, 'category' => $categoryId]);
    }

    /**
     * Move a category from one segment to another atomically.
     */
    public static function move(int $oldSegmentId, int $newSegmentId, int $categoryId): void {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $del = $db->prepare('DELETE FROM segment_categories WHERE segment_id = :old AND category_id = :category');
            $del->execute(['old' => $oldSegmentId, 'category' => $categoryId]);

            $ins = $db->prepare('INSERT INTO segment_categories (segment_id, category_id) VALUES (:new, :category)');
            $ins->execute(['new' => $newSegmentId, 'category' => $categoryId]);

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
?>
