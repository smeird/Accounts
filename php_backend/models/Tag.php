<?php
require_once __DIR__ . '/../Database.php';

class Tag {
    public static function create(string $name, ?string $keyword = null): int {
        $db = Database::getConnection();
        $stmt = $db->prepare('INSERT INTO `tags` (`name`, `keyword`) VALUES (:name, :keyword)');
        $stmt->execute(['name' => $name, 'keyword' => $keyword]);
        return (int)$db->lastInsertId();
    }

    public static function all(): array {
        $db = Database::getConnection();
        $stmt = $db->query('SELECT `id`, `name`, `keyword` FROM `tags`');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function findMatch(string $text): ?int {
        $db = Database::getConnection();
        $stmt = $db->query('SELECT `id`, `keyword` FROM `tags` WHERE `keyword` IS NOT NULL AND `keyword` != ""');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (stripos($text, $row['keyword']) !== false) {
                return (int)$row['id'];
            }
        }
        return null;
    }
}
?>
