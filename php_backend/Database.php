<?php
// Provides a shared PDO connection to the application's database.
class Database {
    private static $instance = null;

    /**
     * Return a singleton PDO connection using environment credentials.
     */
    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $dsn = getenv('DB_DSN');
            if ($dsn) {
                $user = getenv('DB_USER') ?: null;
                $pass = getenv('DB_PASS') ?: null;
            } else {
                $host = getenv('DB_HOST') ?: 'localhost';
                $name = getenv('DB_NAME') ?: 'finance';
                $user = getenv('DB_USER') ?: 'root';
                $pass = getenv('DB_PASS') ?: '';
                $dsn = "mysql:host=$host;dbname=$name;charset=utf8mb4";
            }
            self::$instance = new PDO($dsn, $user, $pass);
            self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        return self::$instance;
    }
}
?>
