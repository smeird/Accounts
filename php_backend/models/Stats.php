<?php
// Aggregates simple system-wide metrics for dashboards and landing pages.
require_once __DIR__ . '/../Database.php';

class Stats {
    /**
     * Fetch overall counts used by the landing page hero metrics.
     */
    public static function getLandingMetrics(): array {
        $db = Database::getConnection();

        $counts = [
            'accounts' => 0,
            'transactions' => 0,
            'tags' => 0,
        ];

        $queries = [
            'accounts' => 'SELECT COUNT(*) FROM `accounts`',
            'transactions' => 'SELECT COUNT(*) FROM `transactions`',
            'tags' => 'SELECT COUNT(*) FROM `tags`',
        ];

        foreach ($queries as $key => $sql) {
            $stmt = $db->query($sql);
            $value = $stmt->fetchColumn();
            $counts[$key] = $value === false ? 0 : (int)$value;
        }

        return $counts;
    }
}
