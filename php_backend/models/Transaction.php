<?php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/Tag.php';

class Transaction {
    public static function create(int $account, string $date, float $amount, string $description, ?int $category = null, ?int $tag = null, ?int $group = null, ?string $ofx_id = null): int {
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

        $stmt = $db->prepare('INSERT INTO transactions (`account_id`, `date`, `amount`, `description`, `category_id`, `tag_id`, `group_id`, `ofx_id`) VALUES (:account, :date, :amount, :description, :category, :tag, :group, :ofx_id)');
        $stmt->execute([
            'account' => $account,
            'date' => $date,
            'amount' => $amount,
            'description' => $description,
            'category' => $category,
            'tag' => $tag,
            'group' => $group,
            'ofx_id' => $ofx_id
        ]);
        return (int)$db->lastInsertId();
    }


    public static function getByCategory(int $categoryId): array {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT `date`, `amount`, `description` FROM `transactions` WHERE `category_id` = :category');
        $stmt->execute(['category' => $categoryId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getByTag(int $tagId): array {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT `date`, `amount`, `description` FROM `transactions` WHERE `tag_id` = :tag');
        $stmt->execute(['tag' => $tagId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getByGroup(int $groupId): array {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT `date`, `amount`, `description` FROM `transactions` WHERE `group_id` = :grp');
        $stmt->execute(['grp' => $groupId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getByMonth(int $month, int $year): array {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM `transactions` WHERE MONTH(`date`) = :month AND YEAR(`date`) = :year ORDER BY `date`');
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
     * Search transactions by a specific field.
     * Supports partial matches for text fields and exact matches for numeric fields.
     */
    public static function search(string $field, string $value): array {
        $allowed = ['id','account_id','date','amount','description','category_id','tag_id','group_id','ofx_id'];
        if (!in_array($field, $allowed, true)) {
            throw new Exception('Invalid search field');
        }

        $db = Database::getConnection();

        // numeric fields use exact match
        $numeric = ['id','account_id','category_id','tag_id','group_id','amount'];
        if (in_array($field, $numeric, true)) {
            $stmt = $db->prepare("SELECT * FROM `transactions` WHERE `$field` = :val");
            $stmt->execute(['val' => $value]);
        } else {
            $stmt = $db->prepare("SELECT * FROM `transactions` WHERE `$field` LIKE :val");
            $stmt->execute(['val' => '%' . $value . '%']);
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
