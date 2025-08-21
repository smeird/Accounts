<?php
// Lightweight logger storing application messages in the database.
require_once __DIR__ . '/../Database.php';

class Log {
    /**
     * Persist a message and level to the logs table.
     */
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

    /**
     * Fetch the most recent log entries up to the provided limit and optional day range.
     */
    public static function all(int $limit = 100, ?int $days = null): array {
        $db = Database::getConnection();
        $sql = 'SELECT id, level, message, created_at FROM logs';
        if ($days !== null) {
            $sql .= ' WHERE created_at >= (NOW() - INTERVAL ' . (int)$days . ' DAY)';
        }
        $sql .= ' ORDER BY created_at DESC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int)$limit;
        }
        $stmt = $db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Remove log entries older than the specified number of days.
     */
    public static function prune(int $days): void {
        try {
            $db = Database::getConnection();
            $db->exec('DELETE FROM logs WHERE created_at < (NOW() - INTERVAL ' . (int)$days . ' DAY)');
        } catch (Throwable $e) {
            error_log('Log prune failed: ' . $e->getMessage());
        }
    }

    /**
     * Register global error and exception handlers that log automatically.
     */
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

