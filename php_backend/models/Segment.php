<?php
// Model for managing segments and assigning them to categories and transactions.
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/Tag.php';

class Segment {
    /**
     * Create a new segment and return its ID.
     */
    public static function create(string $name, ?string $description = null): int {
        $db = Database::getConnection();
        $stmt = $db->prepare('INSERT INTO segments (name, description) VALUES (:name, :description)');
        $stmt->execute(['name' => $name, 'description' => $description]);
        return (int)$db->lastInsertId();
    }

    /**
     * Update the details of an existing segment.
     */
    public static function update(int $id, string $name, ?string $description = null): void {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE segments SET name = :name, description = :description WHERE id = :id');
        $stmt->execute(['id' => $id, 'name' => $name, 'description' => $description]);
    }

    /**
     * Delete a segment and clear references from categories and transactions.
     */
    public static function delete(int $id): void {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('UPDATE categories SET segment_id = NULL WHERE segment_id = :id');
            $stmt->execute(['id' => $id]);
            $stmt = $db->prepare('UPDATE transactions SET segment_id = NULL WHERE segment_id = :id');
            $stmt->execute(['id' => $id]);
            $stmt = $db->prepare('DELETE FROM segments WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Assign a category to a segment. Pass null to remove the category from any segment.
     */
    public static function assignCategory(?int $segmentId, int $categoryId): void {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE categories SET segment_id = :segment WHERE id = :category');
        $stmt->execute(['segment' => $segmentId, 'category' => $categoryId]);
    }

    /**
     * Return all segments with id, name and description.
     */
    public static function all(): array {
        $db = Database::getConnection();
        $stmt = $db->query('SELECT id, name, description FROM segments');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return all segments with their associated categories.
     */
    public static function allWithCategories(): array {
        $db = Database::getConnection();
        $sql = 'SELECT s.id AS segment_id, s.name AS segment_name, s.description AS segment_description, '
             . 'c.id AS category_id, c.name AS category_name '
             . 'FROM segments s '
             . 'LEFT JOIN categories c ON c.segment_id = s.id '
             . 'ORDER BY s.id, c.id';
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $segments = [];
        foreach ($rows as $row) {
            $sid = (int)$row['segment_id'];
            if (!isset($segments[$sid])) {
                $segments[$sid] = [
                    'id' => $sid,
                    'name' => $row['segment_name'],
                    'description' => $row['segment_description'],
                    'categories' => []
                ];
            }
            if ($row['category_id'] !== null) {
                $segments[$sid]['categories'][] = [
                    'id' => (int)$row['category_id'],
                    'name' => $row['category_name']
                ];
            }
        }
        return array_values($segments);
    }

    /**
     * Populate transaction segment ids based on their categories.
     * Returns the number of transactions updated.
     */
    public static function applyToTransactions(): int {
        $db = Database::getConnection();
        $sql = 'UPDATE transactions t '
             . 'JOIN categories c ON t.category_id = c.id '
             . 'SET t.segment_id = c.segment_id '
             . 'WHERE c.segment_id IS NOT NULL '
             . 'AND (t.segment_id IS NULL OR t.segment_id != c.segment_id)';
        $stmt = $db->prepare($sql);
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Clear segment assignments from all transactions.
     * Returns the number of rows affected.
     */
    public static function clearFromTransactions(): int {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE transactions SET segment_id = NULL WHERE segment_id IS NOT NULL');
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Return totals grouped by segment.
     */
    public static function totals(): array {
        $db = Database::getConnection();
        $ignore = Tag::getIgnoreId();
        $sql = 'SELECT COALESCE(s.name, "Not Segmented") AS name, SUM(t.amount) AS total '
             . 'FROM transactions t '
             . 'LEFT JOIN segments s ON t.segment_id = s.id '
             . 'WHERE t.transfer_id IS NULL AND (t.tag_id IS NULL OR t.tag_id != :ignore) '
             . 'GROUP BY name '
             . 'ORDER BY total DESC';
        $stmt = $db->prepare($sql);
        $stmt->execute(['ignore' => $ignore]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
