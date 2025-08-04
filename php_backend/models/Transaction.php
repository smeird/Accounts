<?php
// Model representing financial transactions and related queries.
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/Tag.php';

class Transaction {
    public static function create(int $account, string $date, float $amount, string $description, ?string $memo = null, ?int $category = null, ?int $tag = null, ?int $group = null, ?string $ofx_id = null): int {
        if ($tag === null) {
            $tag = Tag::findMatch($description);
        }
        $db = Database::getConnection();

        // avoid duplicate inserts when an OFX id already exists
        if ($ofx_id !== null) {
            $check = $db->prepare('SELECT id FROM `transactions` WHERE `ofx_id` = :oid LIMIT 1');
            $check->execute(['oid' => $ofx_id]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                return (int)$existing['id'];
            }
        }

        $stmt = $db->prepare('INSERT INTO transactions (`account_id`, `date`, `amount`, `description`, `memo`, `category_id`, `tag_id`, `group_id`, `ofx_id`) VALUES (:account, :date, :amount, :description, :memo, :category, :tag, :group, :ofx_id)');
        $stmt->execute([
            'account' => $account,
            'date' => $date,
            'amount' => $amount,
            'description' => $description,
            'memo' => $memo,
            'category' => $category,
            'tag' => $tag,
            'group' => $group,
            'ofx_id' => $ofx_id
        ]);
        return (int)$db->lastInsertId();
    }


    public static function getByCategory(int $categoryId): array {
        $db = Database::getConnection();
        $sql = 'SELECT t.`date`, t.`amount`, t.`description`, '
             . 'c.`name` AS category_name, tg.`name` AS tag_name, g.`name` AS group_name '
             . 'FROM `transactions` t '
             . 'LEFT JOIN `categories` c ON t.`category_id` = c.`id` '
             . 'LEFT JOIN `tags` tg ON t.`tag_id` = tg.`id` '
             . 'LEFT JOIN `transaction_groups` g ON t.`group_id` = g.`id` '
             . 'WHERE t.`category_id` = :category';
        $stmt = $db->prepare($sql);
        $stmt->execute(['category' => $categoryId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getByTag(int $tagId): array {
        $db = Database::getConnection();
        $sql = 'SELECT t.`date`, t.`amount`, t.`description`, '
             . 'c.`name` AS category_name, tg.`name` AS tag_name, g.`name` AS group_name '
             . 'FROM `transactions` t '
             . 'LEFT JOIN `categories` c ON t.`category_id` = c.`id` '
             . 'LEFT JOIN `tags` tg ON t.`tag_id` = tg.`id` '
             . 'LEFT JOIN `transaction_groups` g ON t.`group_id` = g.`id` '
             . 'WHERE t.`tag_id` = :tag';
        $stmt = $db->prepare($sql);
        $stmt->execute(['tag' => $tagId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getByGroup(int $groupId): array {
        $db = Database::getConnection();
        $sql = 'SELECT t.`date`, t.`amount`, t.`description`, '
             . 'c.`name` AS category_name, tg.`name` AS tag_name, g.`name` AS group_name '
             . 'FROM `transactions` t '
             . 'LEFT JOIN `categories` c ON t.`category_id` = c.`id` '
             . 'LEFT JOIN `tags` tg ON t.`tag_id` = tg.`id` '
             . 'LEFT JOIN `transaction_groups` g ON t.`group_id` = g.`id` '
             . 'WHERE t.`group_id` = :grp';
        $stmt = $db->prepare($sql);
        $stmt->execute(['grp' => $groupId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getByMonth(int $month, int $year): array {
        $db = Database::getConnection();
        $sql = 'SELECT t.`id`, t.`account_id`, t.`date`, t.`amount`, t.`description`, t.`memo`, '
             . 'c.`name` AS category_name, tg.`name` AS tag_name, g.`name` AS group_name '
             . 'FROM `transactions` t '
             . 'LEFT JOIN `categories` c ON t.`category_id` = c.`id` '
             . 'LEFT JOIN `tags` tg ON t.`tag_id` = tg.`id` '
             . 'LEFT JOIN `transaction_groups` g ON t.`group_id` = g.`id` '
             . 'WHERE MONTH(t.`date`) = :month AND YEAR(t.`date`) = :year '
             . 'ORDER BY t.`date`';
        $stmt = $db->prepare($sql);
        $stmt->execute(['month' => $month, 'year' => $year]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getAvailableMonths(): array {
        $db = Database::getConnection();
        $stmt = $db->query('SELECT DISTINCT YEAR(`date`) AS year, MONTH(`date`) AS month FROM `transactions` ORDER BY YEAR(`date`) DESC, MONTH(`date`) DESC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve the total amount spent in each month of a given year.
     * Amounts are returned as positive numbers representing outflows.
     * Months with no spending will have a total of 0.
     */
    public static function getMonthlySpending(int $year): array {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT MONTH(`date`) AS `month`, SUM(CASE WHEN `amount` < 0 THEN -`amount` ELSE 0 END) AS `spent`
            FROM `transactions`
            WHERE YEAR(`date`) = :year
            GROUP BY MONTH(`date`)
            ORDER BY MONTH(`date`)');
        $stmt->execute(['year' => $year]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Ensure all months are present in the result
        $result = [];
        for ($m = 1; $m <= 12; $m++) {
            $result[$m] = 0.0;
        }
        foreach ($rows as $row) {
            $month = (int)$row['month'];
            $result[$month] = isset($row['spent']) ? (float)$row['spent'] : 0.0;
        }

        $output = [];
        foreach ($result as $month => $spent) {
            $output[] = ['month' => $month, 'spent' => $spent];
        }
        return $output;
    }

    /**
     * Retrieve income and outgoings totals for a given month.
     * Returns income and outgoings as positive numbers with delta calculated.
     */
    public static function getMonthlyTotals(int $month, int $year): array {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT
                SUM(CASE WHEN t.`amount` > 0 THEN t.`amount` ELSE 0 END) AS income,
                SUM(CASE WHEN t.`amount` < 0 THEN -t.`amount` ELSE 0 END) AS outgoings
             FROM `transactions` t
             WHERE MONTH(t.`date`) = :month AND YEAR(t.`date`) = :year'
        );
        $stmt->execute(['month' => $month, 'year' => $year]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $income = isset($row['income']) ? (float)$row['income'] : 0.0;
        $outgoings = isset($row['outgoings']) ? (float)$row['outgoings'] : 0.0;
        $delta = $income - $outgoings;
        return ['income' => $income, 'outgoings' => $outgoings, 'delta' => $delta];
    }

    /**
     * Retrieve total spending by tag for a given month.
     * Returns tag name and total spent as positive numbers ordered by total descending.
     */
    public static function getTagTotalsByMonth(int $month, int $year): array {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT tg.`name` AS `name`,
                    SUM(CASE WHEN t.`amount` < 0 THEN -t.`amount` ELSE 0 END) AS `total`
             FROM `transactions` t
             JOIN `tags` tg ON t.`tag_id` = tg.`id`
             WHERE MONTH(t.`date`) = :month AND YEAR(t.`date`) = :year
             GROUP BY tg.`id`, tg.`name`
             ORDER BY `total` DESC'
        );
        $stmt->execute(['month' => $month, 'year' => $year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve total spending by category for a given month.
     * Returns category name and total spent as positive numbers ordered by total descending.
     */
    public static function getCategoryTotalsByMonth(int $month, int $year): array {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT c.`name` AS `name`,
                    SUM(CASE WHEN t.`amount` < 0 THEN -t.`amount` ELSE 0 END) AS `total`
             FROM `transactions` t
             JOIN `categories` c ON t.`category_id` = c.`id`
             WHERE MONTH(t.`date`) = :month AND YEAR(t.`date`) = :year
             GROUP BY c.`id`, c.`name`
             ORDER BY `total` DESC'
        );
        $stmt->execute(['month' => $month, 'year' => $year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve total spending by group for a given month.
     * Returns group name and total spent as positive numbers ordered by total descending.
     */
    public static function getGroupTotalsByMonth(int $month, int $year): array {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT g.`name` AS `name`,
                    SUM(CASE WHEN t.`amount` < 0 THEN -t.`amount` ELSE 0 END) AS `total`
             FROM `transactions` t
             JOIN `transaction_groups` g ON t.`group_id` = g.`id`
             WHERE MONTH(t.`date`) = :month AND YEAR(t.`date`) = :year
             GROUP BY g.`id`, g.`name`
             ORDER BY `total` DESC'
        );
        $stmt->execute(['month' => $month, 'year' => $year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**

     * Retrieve total spending by tag for a given year.
     * Returns tag name and total spent as positive numbers ordered by total descending.
     */
    public static function getTagTotalsByYear(int $year): array {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT tg.`name` AS `name`,
                    SUM(CASE WHEN t.`amount` < 0 THEN -t.`amount` ELSE 0 END) AS `total`
             FROM `transactions` t
             JOIN `tags` tg ON t.`tag_id` = tg.`id`
             WHERE YEAR(t.`date`) = :year
             GROUP BY tg.`id`, tg.`name`
             ORDER BY `total` DESC'
        );
        $stmt->execute(['year' => $year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve total spending by category for a given year.
     * Returns category name and total spent as positive numbers ordered by total descending.
     */
    public static function getCategoryTotalsByYear(int $year): array {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT c.`name` AS `name`,
                    SUM(CASE WHEN t.`amount` < 0 THEN -t.`amount` ELSE 0 END) AS `total`
             FROM `transactions` t
             JOIN `categories` c ON t.`category_id` = c.`id`
             WHERE YEAR(t.`date`) = :year
             GROUP BY c.`id`, c.`name`
             ORDER BY `total` DESC'
        );
        $stmt->execute(['year' => $year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve total spending by group for a given year.
     * Returns group name and total spent as positive numbers ordered by total descending.
     */
    public static function getGroupTotalsByYear(int $year): array {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT g.`name` AS `name`,
                    SUM(CASE WHEN t.`amount` < 0 THEN -t.`amount` ELSE 0 END) AS `total`
             FROM `transactions` t
             JOIN `transaction_groups` g ON t.`group_id` = g.`id`
             WHERE YEAR(t.`date`) = :year
             GROUP BY g.`id`, g.`name`
             ORDER BY `total` DESC'
        );
        $stmt->execute(['year' => $year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Search transactions by a specific field.
     * Supports partial matches for text fields and exact matches for numeric fields.

     */
    public static function search(string $value): array {
        $db = Database::getConnection();

        $sql = 'SELECT t.`id`, t.`account_id`, t.`date`, t.`amount`, t.`description`, t.`memo`, '
             . 'c.`name` AS category_name, tg.`name` AS tag_name, g.`name` AS group_name '
             . 'FROM `transactions` t '
             . 'LEFT JOIN `categories` c ON t.`category_id` = c.`id` '
             . 'LEFT JOIN `tags` tg ON t.`tag_id` = tg.`id` '
             . 'LEFT JOIN `transaction_groups` g ON t.`group_id` = g.`id` '
             . 'WHERE (t.`description` LIKE :val '
             . 'OR t.`memo` LIKE :val '
             . 'OR t.`date` LIKE :val '
             . 'OR t.`ofx_id` LIKE :val '
             . 'OR c.`name` LIKE :val '
             . 'OR tg.`name` LIKE :val '
             . 'OR g.`name` LIKE :val';

        $params = ['val' => '%' . $value . '%'];

        if (is_numeric($value)) {
            $sql .= ' OR t.`id` = :num'
                  . ' OR t.`account_id` = :num'
                  . ' OR t.`category_id` = :num'
                  . ' OR t.`tag_id` = :num'
                  . ' OR t.`group_id` = :num'
                  . ' OR t.`amount` = :num';
            $params['num'] = $value;
        }

        $sql .= ')';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
