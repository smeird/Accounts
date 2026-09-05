<?php
// Provides a shared PDO connection to the application's PostgreSQL database.
class ApplicationPDO extends PDO {
    public static function normaliseSql(string $sql, string $driver): string {
        if ($driver !== 'pgsql') {
            return $sql;
        }

        // Normalise the remaining legacy query syntax at the PostgreSQL
        // boundary while application queries are progressively simplified.
        $sql = str_replace('`', '"', $sql);
        $sql = preg_replace('/\bLIKE\b/i', 'ILIKE', $sql);
        $sql = preg_replace('/\bIFNULL\s*\(/i', 'COALESCE(', $sql);
        $sql = preg_replace('/\bYEAR\s*\(([^()]+)\)/i', 'EXTRACT(YEAR FROM $1)', $sql);
        $sql = preg_replace('/\bMONTH\s*\(([^()]+)\)/i', 'EXTRACT(MONTH FROM $1)', $sql);
        $sql = preg_replace('/DATE_SUB\s*\(\s*CURDATE\s*\(\s*\)\s*,\s*INTERVAL\s+(\d+)\s+MONTH\s*\)/i', "(CURRENT_DATE - INTERVAL '$1 months')", $sql);
        $sql = preg_replace('/\bCURDATE\s*\(\s*\)/i', 'CURRENT_DATE', $sql);
        $sql = preg_replace('/ABS\s*\(\s*DATEDIFF\s*\(([^,]+),\s*([^\)]+)\)\s*\)/i', 'ABS(($1)::date - ($2)::date)', $sql);
        $sql = preg_replace('/GROUP_CONCAT\s*\(\s*([^\)]+)\s*\)/i', "STRING_AGG(CAST($1 AS TEXT), ',')", $sql);
        $sql = preg_replace('/([\w\."()]+)\s*<=>\s*(:\w+)/', '$1 IS NOT DISTINCT FROM $2', $sql);
        return $sql;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(self::normaliseSql($query, $this->getAttribute(PDO::ATTR_DRIVER_NAME)), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $query = self::normaliseSql($query, $this->getAttribute(PDO::ATTR_DRIVER_NAME));
        return $fetchMode === null
            ? parent::query($query)
            : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false {
        return parent::exec(self::normaliseSql($statement, $this->getAttribute(PDO::ATTR_DRIVER_NAME)));
    }

    public function lastInsertId(?string $name = null): string|false {
        if ($this->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' && $name === null) {
            $value = parent::query('SELECT LASTVAL()')->fetchColumn();
            return $value === false ? false : (string)$value;
        }
        return parent::lastInsertId($name);
    }
}

class Database {
    private static $instance = null;

    /**
     * Return a singleton PDO connection using environment credentials.
     */
    public static function getConnection(): PDO {
        if (self::$instance === null) {
            if (PHP_VERSION_ID < 80500) {
                throw new RuntimeException('Finance Manager requires PHP 8.5 or newer.');
            }
            $dsn = getenv('DB_DSN');
            if ($dsn) {
                $user = getenv('DB_USER') ?: null;
                $pass = getenv('DB_PASS') ?: null;
            } else {
                $host = getenv('DB_HOST') ?: 'localhost';
                $name = getenv('DB_NAME') ?: 'finance';
                $port = getenv('DB_PORT') ?: '5432';
                $user = getenv('DB_USER') ?: 'finance';
                $pass = getenv('DB_PASS') ?: '';
                $sslMode = getenv('DB_SSLMODE') ?: 'prefer';
                $dsn = "pgsql:host=$host;port=$port;dbname=$name;sslmode=$sslMode";
            }
            self::$instance = new ApplicationPDO($dsn, $user, $pass);
            self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        }
        return self::$instance;
    }
}
