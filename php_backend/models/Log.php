<?php
require_once __DIR__ . '/../Database.php';

class Log {
    public static function write(string $message, string $level = 'INFO'): void {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare('INSERT INTO logs (level, message) VALUES (:level, :message)');
            $stmt->execute(['level' => $level, 'message' => $message]);
        } catch (Throwable $e) {
            // Avoid cascading failures if the database is unavailable
            error_log('Logging failed: ' . $e->getMessage());
        }
    }

    public static function all(int $limit = 100): array {
        $db = Database::getConnection();
        $sql = 'SELECT id, level, message, created_at FROM logs ORDER BY created_at DESC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int)$limit;
        }
        $stmt = $db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function registerHandlers(): void {
        error_reporting(E_ALL);

        set_error_handler(function ($severity, $message, $file, $line): bool {
            self::write("$message in $file on line $line", 'ERROR');
            return false;
        });

        set_exception_handler(function (Throwable $e): void {
            self::write($e->getMessage(), 'ERROR');
        });

        register_shutdown_function(function (): void {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                self::write("{$error['message']} in {$error['file']} on line {$error['line']}", 'ERROR');
            }
        });
    }
}

Log::registerHandlers();
