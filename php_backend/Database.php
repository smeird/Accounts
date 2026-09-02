<?php
// Provides a shared PDO connection to the application's database.
class PostgresPdo extends PDO {
    private function translate(string $sql): string {
        // The application historically used MySQL identifier quoting. PostgreSQL
        // needs standard SQL quotes; converting here keeps read queries portable.
        $sql = str_replace('`', '"', $sql);
        $sql = preg_replace('/IFNULL\\s*\\(/i', 'COALESCE(', $sql);
        $sql = preg_replace('/NOW\\(\\)\\s*-\\s*INTERVAL\\s+(\\d+)\\s+DAY/i', "CURRENT_TIMESTAMP - INTERVAL '$1 days'", $sql);
        return preg_replace('/DATE_SUB\\(CURDATE\\(\\),\\s*INTERVAL\\s+(\\d+)\\s+MONTH\\)/i', "CURRENT_DATE - INTERVAL '$1 months'", $sql);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare($this->translate($query), $options);
    }

    public function exec(string $statement): int|false {
        return parent::exec($this->translate($statement));
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $query = $this->translate($query);
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

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
                $pass = getenv('DB_PASS') ?: ' ';
                $dsn = "mysql:host=$host;dbname=$name;charset=utf8mb4";
            }
            self::$instance = str_starts_with($dsn, 'pgsql:')
                ? new PostgresPdo($dsn, $user, $pass)
                : new PDO($dsn, $user, $pass);
            self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        return self::$instance;
    }
}
