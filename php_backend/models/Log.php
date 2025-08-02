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
            $sql .= ' LIMIT ' . (int)$limit;
        }
        $stmt = $db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function registerHandlers(): void {
        set_error_handler(function ($severity, $message, $file, $line): bool {
            self::write("$message in $file on line $line", 'ERROR');
            return false;
        });

        set_exception_handler(function (Throwable $e): void {
            self::write($e->getMessage(), 'ERROR');
        });
    }
}

Log::registerHandlers();
?>
