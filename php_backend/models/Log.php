<?php
require_once __DIR__ . '/../Database.php';

class Log {
    public static function write(string $message, string $level = 'INFO'): void {
        $db = Database::getConnection();
        $stmt = $db->prepare('INSERT INTO logs (level, message) VALUES (:level, :message)');
        $stmt->execute(['level' => $level, 'message' => $message]);
    }
}
?>
