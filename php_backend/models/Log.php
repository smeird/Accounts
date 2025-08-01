<?php
require_once __DIR__ . '/../Database.php';

class Log {
    public static function write(string $message, string $level = 'INFO'): void {
        $db = Database::getConnection();
        $stmt = $db->prepare('INSERT INTO logs (level, message) VALUES (:level, :message)');
        $stmt->execute(['level' => $level, 'message' => $message]);
    }


    public static function all(int $limit = 100): array {
        $db = Database::getConnection();
        $sql = 'SELECT level, message, created_at FROM logs ORDER BY created_at DESC';
        if ($limit !== null) {
            $sql .= ' LIMIT :limit';
        }
        $stmt = $db->prepare($sql);
        if ($limit !== null) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
