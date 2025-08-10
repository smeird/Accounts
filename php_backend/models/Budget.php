<?php
// Model for category budgets by month.
require_once __DIR__ . '/../Database.php';

class Budget {
    /**
     * Create or update a budget for a category for a given month and year.
     */
    public static function set(int $categoryId, int $month, int $year, float $amount): void {
        $db = Database::getConnection();
        $stmt = $db->prepare('INSERT INTO budgets (category_id, month, year, amount) VALUES (:cid, :month, :year, :amount) '
            . 'ON DUPLICATE KEY UPDATE amount = VALUES(amount)');
        $stmt->execute([
            'cid' => $categoryId,
            'month' => $month,
            'year' => $year,
            'amount' => $amount
        ]);
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

        $spentStmt = $db->prepare('SELECT COALESCE(SUM(amount),0) FROM transactions '
            . 'WHERE category_id = :cid AND MONTH(`date`) = :month AND YEAR(`date`) = :year AND transfer_id IS NULL');
        foreach ($budgets as &$b) {
            $spentStmt->execute(['cid' => $b['category_id'], 'month' => $month, 'year' => $year]);
            $total = (float)$spentStmt->fetchColumn();
            $spent = $total < 0 ? -$total : 0; // expenses are negative amounts
            $b['spent'] = $spent;
            $b['left'] = (float)$b['amount'] - $spent;
        }
        return $budgets;
    }
}
?>
