<?php
// Parses plain-English report requests into Transaction::filter parameters.
require_once __DIR__ . '/Database.php';

class NaturalLanguageReportParser {
    /**
     * Convert a free-text query into filters for Transaction::filter().
     * Recognises category, tag, segment, group names and simple date ranges.
     */
    public static function parse(string $query): array {
        $filters = [
            'category' => null,
            'tag' => null,
            'group' => null,
            'segment' => null,
            'start' => null,
            'end' => null,
            'text' => null,
        ];

        $q = strtolower($query);
        $filters['category'] = self::matchName($q, 'categories');
        $filters['tag'] = self::matchName($q, 'tags');
        $filters['group'] = self::matchName($q, 'transaction_groups');
        $filters['segment'] = self::matchName($q, 'segments');

        // Basic date range parsing ("last N months" or "last N years").
        if (preg_match('/last\s+(\d+)\s+months?/', $q, $m)) {
            $months = (int)$m[1];
            $filters['start'] = date('Y-m-d', strtotime("-$months months"));
            $filters['end'] = date('Y-m-d');
        } elseif (preg_match('/last\s+(\d+)\s+years?/', $q, $m)) {
            $years = (int)$m[1];
            $filters['start'] = date('Y-m-d', strtotime("-$years years"));
            $filters['end'] = date('Y-m-d');
        } elseif (strpos($q, 'last year') !== false) {
            $filters['start'] = date('Y-m-d', strtotime('-1 year'));
            $filters['end'] = date('Y-m-d');
        } elseif (strpos($q, 'last month') !== false) {
            $filters['start'] = date('Y-m-d', strtotime('-1 month'));
            $filters['end'] = date('Y-m-d');
        }

        return $filters;
    }

    /**
     * Find the id of an entity in a table whose name appears in the query.
     */
    private static function matchName(string $query, string $table): ?int {
        $db = Database::getConnection();
        $stmt = $db->query("SELECT id, name FROM $table");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (stripos($query, strtolower($row['name'])) !== false) {
                return (int)$row['id'];
            }
        }
        return null;
    }
}
?>
