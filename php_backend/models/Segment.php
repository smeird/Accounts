<?php
// Model for retrieving transaction segments.
require_once __DIR__ . '/../Database.php';

class Segment {
    /**
     * Return all segments with id, name, and description.
     */
    public static function all(): array {
        $db = Database::getConnection();
        $stmt = $db->query('SELECT `id`, `name`, `description` FROM `segments`');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
