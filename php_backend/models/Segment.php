<?php
// Model handling segment records and their category mappings.
require_once __DIR__ . '/../Database.php';

class Segment {
    /**
     * Insert a new segment and return its ID.
     */
    public static function create(string $name, ?string $description = null): int {
        $db = Database::getConnection();
        $stmt = $db->prepare('INSERT INTO segments (name, description) VALUES (:name, :description)');
        $stmt->execute(['name' => $name, 'description' => $description]);
        return (int)$db->lastInsertId();
    }

    /**
     * Update the name and description of an existing segment.
     */
    public static function update(int $id, string $name, ?string $description = null): void {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE segments SET name = :name, description = :description WHERE id = :id');
        $stmt->execute(['id' => $id, 'name' => $name, 'description' => $description]);
    }

    /**
     * Retrieve all segments and their associated categories.
     */
    public static function allWithCategories(): array {
        $db = Database::getConnection();
        $sql = 'SELECT s.id AS segment_id, s.name AS segment_name, s.description AS segment_description, '
             . 'c.id AS category_id, c.name AS category_name '
             . 'FROM segments s '
             . 'LEFT JOIN segment_categories sc ON s.id = sc.segment_id '
             . 'LEFT JOIN categories c ON c.id = sc.category_id '
             . 'ORDER BY s.id';
        $stmt = $db->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $segments = [];
        foreach ($rows as $row) {
            $id = (int)$row['segment_id'];
            if (!isset($segments[$id])) {
                $segments[$id] = [
                    'id' => $id,
                    'name' => $row['segment_name'],
                    'description' => $row['segment_description'],
                    'categories' => []
                ];
            }
            if ($row['category_id'] !== null) {
                $segments[$id]['categories'][] = [
                    'id' => (int)$row['category_id'],
                    'name' => $row['category_name']
                ];
            }
        }
        return array_values($segments);
    }

    /**
     * Delete a segment and remove all category mappings to it.
     */
    public static function delete(int $id): void {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('DELETE FROM segment_categories WHERE segment_id = :id');
            $stmt->execute(['id' => $id]);

            $stmt = $db->prepare('DELETE FROM segments WHERE id = :id');
            $stmt->execute(['id' => $id]);

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
?>
