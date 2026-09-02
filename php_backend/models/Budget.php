<?php
// Model for category budgets by month.
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/Tag.php';

class Budget {
    /**
     * Create or update a budget for a category for a given month and year.
     * Returns an array with rowCount and errorInfo for logging.
     */
    public static function set(int $categoryId, int $month, int $year, float $amount): array {
        $db = Database::getConnection();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $upsert = $driver === 'sqlite' || $driver === 'pgsql'
            ? 'ON CONFLICT(category_id, month, year) DO UPDATE SET amount = excluded.amount'
            : 'ON DUPLICATE KEY UPDATE amount = VALUES(amount)';
        $stmt = $db->prepare('INSERT INTO budgets (category_id, month, year, amount) VALUES (:cid, :month, :year, :amount) ' . $upsert);
        $stmt->execute([
            'cid' => $categoryId,
            'month' => $month,
            'year' => $year,
            'amount' => $amount
        ]);
        return ['rowCount' => $stmt->rowCount(), 'errorInfo' => $stmt->errorInfo()];
    }

    /**
     * Delete a budget by its ID.
     */
    public static function delete(int $id): void {
        $db = Database::getConnection();
        $stmt = $db->prepare('DELETE FROM budgets WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Retrieve budgets and spending for a given month and year.
     * Returns category name, budget amount, spent, and remaining.
     */
    public static function getMonthly(int $month, int $year): array {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT b.id, b.category_id, c.name AS category, b.amount '
            . 'FROM budgets b JOIN categories c ON b.category_id = c.id '
            . 'WHERE b.month = :month AND b.year = :year ORDER BY c.name');
        $stmt->execute(['month' => $month, 'year' => $year]);
        $budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $ignore = Tag::getIgnoreId();
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = (new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');
        $spentStmt = $db->prepare('SELECT COALESCE(SUM(amount),0) FROM transactions '
            . 'WHERE category_id = :cid AND `date` >= :start AND `date` < :end '
            . 'AND transfer_id IS NULL AND (tag_id IS NULL OR tag_id != :ignore)');
        foreach ($budgets as &$b) {
            $spentStmt->execute(['cid' => $b['category_id'], 'start' => $start, 'end' => $end, 'ignore' => $ignore]);
            $total = (float)$spentStmt->fetchColumn();
            $spent = $total < 0 ? -$total : 0; // expenses are negative amounts
            $b['spent'] = $spent;
            $b['left'] = (float)$b['amount'] - $spent;
        }
        unset($b);
        return $budgets;
    }
}
?>
