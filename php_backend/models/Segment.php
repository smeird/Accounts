<?php
// Model managing segments and their category associations.
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
     * Update an existing segment's name and description.
     */
    public static function update(int $id, string $name, ?string $description = null): void {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE segments SET name = :name, description = :description WHERE id = :id');
        $stmt->execute(['id' => $id, 'name' => $name, 'description' => $description]);
    }

    /**
     * Delete a segment and remove any category links.
     */
    public static function delete(int $id): bool {
        $db = Database::getConnection();
        $stmt = $db->prepare('DELETE FROM category_segments WHERE segment_id = :id');
        $stmt->execute(['id' => $id]);
        $stmt = $db->prepare('DELETE FROM segments WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }

    /**
     * Associate a category with a segment.
     */
    public static function assignCategory(int $segmentId, int $categoryId): void {
        $db = Database::getConnection();
        $stmt = $db->prepare('INSERT INTO category_segments (segment_id, category_id) VALUES (:sid, :cid)');
        $stmt->execute(['sid' => $segmentId, 'cid' => $categoryId]);
    }

    /**
     * Return all segments with their associated categories.
     *
     * @return array
     */
    public static function allWithCategories(): array {
        $db = Database::getConnection();
        $sql = 'SELECT s.id AS segment_id, s.name AS segment_name, s.description AS segment_description, '
             . 'c.id AS category_id, c.name AS category_name '
             . 'FROM segments s '
             . 'LEFT JOIN category_segments cs ON s.id = cs.segment_id '
             . 'LEFT JOIN categories c ON cs.category_id = c.id '
             . 'ORDER BY s.id';
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
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
     * Calculate total transaction amounts for each segment.
     *
     * @return array{id:int,name:string,total:float}[]
     */
    public static function totals(): array {
        $db = Database::getConnection();
        $ignore = Tag::getIgnoreId();
        // Sum amounts for transactions whose categories belong to each segment
        // while ignoring transfers and transactions tagged as IGNORE.
        $sql = 'SELECT s.id, s.name, COALESCE(SUM(t.amount), 0) AS total '
             . 'FROM segments s '
             . 'LEFT JOIN category_segments cs ON s.id = cs.segment_id '
             . 'LEFT JOIN transactions t ON t.category_id = cs.category_id '
             . 'AND t.transfer_id IS NULL '
             . 'AND (t.tag_id IS NULL OR t.tag_id != :ignore) '
             . 'GROUP BY s.id, s.name';
        $stmt = $db->prepare($sql);
        $stmt->execute(['ignore' => $ignore]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['total'] = (float)$row['total'];
        }
        return $rows;
    }
}
?>
