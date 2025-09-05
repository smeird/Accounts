<?php
// Model for persisting saved report filters and metadata.
require_once __DIR__ . '/../Database.php';

class SavedReport {
    /**
     * Store a new saved report and return its ID.
     *
     * @param string $name        Report name.
     * @param string $description Optional report description.
     * @param array  $filters     Filter criteria to save.
     *
     * @return int Inserted report ID.
     */
    public static function create(string $name, string $description, array $filters): int {
        $db = Database::getConnection();
        $stmt = $db->prepare('INSERT INTO saved_reports (name, description, filters) VALUES (:name, :description, :filters)');
        $stmt->execute([
            'name' => $name,
            'description' => $description,
            'filters' => json_encode($filters)
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * Retrieve all saved reports with decoded filters.
     *
     * @return array<int, array{id:int, name:string, description:?string, filters:array}>
     */
    public static function all(): array {
        $db = Database::getConnection();
        $stmt = $db->query('SELECT id, name, description, filters FROM saved_reports ORDER BY name');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['filters'] = json_decode($row['filters'], true) ?: [];
        }
        return $rows;
    }

    /**
     * Delete a saved report by ID.
     */
    public static function delete(int $id): bool {
        $db = Database::getConnection();
        $stmt = $db->prepare('DELETE FROM saved_reports WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }
}
?>
