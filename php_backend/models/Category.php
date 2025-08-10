<?php
// Model handling category records and related tag mappings.
require_once __DIR__ . '/../Database.php';

class Category {
    /**
     * Insert a new category and return its ID.
     */
    public static function create(string $name): int {
        $db = Database::getConnection();
        $stmt = $db->prepare('INSERT INTO categories (name) VALUES (:name)');
        $stmt->execute(['name' => $name]);
        return (int)$db->lastInsertId();
    }

    /**
     * Update the name of an existing category.
     */
    public static function update(int $id, string $name): void {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE categories SET name = :name WHERE id = :id');
        $stmt->execute(['id' => $id, 'name' => $name]);
    }

    /**
     * Retrieve all categories and their associated tags.
     */
    public static function allWithTags(): array {
        $db = Database::getConnection();
        $sql = 'SELECT c.id AS category_id, c.name AS category_name, '
             . 't.id AS tag_id, t.name AS tag_name '
             . 'FROM categories c '
             . 'LEFT JOIN category_tags ct ON c.id = ct.category_id '
             . 'LEFT JOIN tags t ON t.id = ct.tag_id '
             . 'ORDER BY c.id';
        $stmt = $db->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $categories = [];
        foreach ($rows as $row) {
            $id = (int)$row['category_id'];
            if (!isset($categories[$id])) {
                $categories[$id] = [
                    'id' => $id,
                    'name' => $row['category_name'],
                    'tags' => []
                ];
            }
            if ($row['tag_id'] !== null) {
                $categories[$id]['tags'][] = [
                    'id' => (int)$row['tag_id'],
                    'name' => $row['tag_name']
                ];
            }
        }
        return array_values($categories);
    }
}
?>
