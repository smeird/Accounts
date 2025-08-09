<?php
// Model representing financial transactions and related queries.
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/Tag.php';

class Transaction {
    /**
     * Insert a new transaction and attempt to auto-tag and link transfers.
     */
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
        $id = (int)$db->lastInsertId();

        // Attempt to detect matching transfer (opposite entry in another account)
        $matchStmt = $db->prepare('SELECT id FROM transactions WHERE account_id != :account AND `date` = :date AND `description` = :description AND `amount` = :oppAmount AND transfer_id IS NULL LIMIT 1');
        $matchStmt->execute([
            'account' => $account,
            'date' => $date,
            'description' => $description,
            'oppAmount' => -$amount
        ]);
        if ($row = $matchStmt->fetch(PDO::FETCH_ASSOC)) {
            $matchId = (int)$row['id'];
            $transferId = min($id, $matchId);
            $upd = $db->prepare('UPDATE transactions SET transfer_id = :tid WHERE id IN (:id1, :id2)');
            $upd->execute(['tid' => $transferId, 'id1' => $id, 'id2' => $matchId]);
        }

        return $id;
    }


    /**
     * Return transactions for a given category excluding transfers.
     */
    public static function getByCategory(int $categoryId): array {
        $db = Database::getConnection();
        $sql = 'SELECT t.`date`, t.`amount`, t.`description`, '
             . 'c.`name` AS category_name, tg.`name` AS tag_name, g.`name` AS group_name '
             . 'FROM `transactions` t '
             . 'LEFT JOIN `categories` c ON t.`category_id` = c.`id` '
             . 'LEFT JOIN `tags` tg ON t.`tag_id` = tg.`id` '
             . 'LEFT JOIN `transaction_groups` g ON t.`group_id` = g.`id` '
             . 'WHERE t.`category_id` = :category AND t.`transfer_id` IS NULL';
        $stmt = $db->prepare($sql);
        $stmt->execute(['category' => $categoryId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return transactions for a given tag excluding transfers.
     */
    public static function getByTag(int $tagId): array {
        $db = Database::getConnection();
        $sql = 'SELECT t.`date`, t.`amount`, t.`description`, '
             . 'c.`name` AS category_name, tg.`name` AS tag_name, g.`name` AS group_name '
             . 'FROM `transactions` t '
             . 'LEFT JOIN `categories` c ON t.`category_id` = c.`id` '
             . 'LEFT JOIN `tags` tg ON t.`tag_id` = tg.`id` '
             . 'LEFT JOIN `transaction_groups` g ON t.`group_id` = g.`id` '
             . 'WHERE t.`tag_id` = :tag AND t.`transfer_id` IS NULL';
        $stmt = $db->prepare($sql);
        $stmt->execute(['tag' => $tagId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return transactions for a given group excluding transfers.
     */
    public static function getByGroup(int $groupId): array {
        $db = Database::getConnection();
        $sql = 'SELECT t.`date`, t.`amount`, t.`description`, '
             . 'c.`name` AS category_name, tg.`name` AS tag_name, g.`name` AS group_name '
             . 'FROM `transactions` t '
             . 'LEFT JOIN `categories` c ON t.`category_id` = c.`id` '
             . 'LEFT JOIN `tags` tg ON t.`tag_id` = tg.`id` '
             . 'LEFT JOIN `transaction_groups` g ON t.`group_id` = g.`id` '
             . 'WHERE t.`group_id` = :grp AND t.`transfer_id` IS NULL';
        $stmt = $db->prepare($sql);
        $stmt->execute(['grp' => $groupId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Filter transactions by optional category, tag, group, text and date range.
     */
    public static function filter(?int $category = null, ?int $tag = null, ?int $group = null, ?string $text = null, ?string $start = null, ?string $end = null): array {
        if ($category === null && $tag === null && $group === null && $text === null && $start === null && $end === null) {
            return [];
        }

        $db = Database::getConnection();
        $sql = 'SELECT t.`date`, t.`amount`, t.`description`, '
             . 'c.`name` AS category_name, tg.`name` AS tag_name, g.`name` AS group_name '
             . 'FROM `transactions` t '
             . 'LEFT JOIN `categories` c ON t.`category_id` = c.`id` '
             . 'LEFT JOIN `tags` tg ON t.`tag_id` = tg.`id` '
             . 'LEFT JOIN `transaction_groups` g ON t.`group_id` = g.`id` '
             . 'WHERE t.`transfer_id` IS NULL';

        $params = [];
        if ($category !== null) {
            $sql .= ' AND t.`category_id` = :category';
            $params['category'] = $category;
        }
        if ($tag !== null) {
            $sql .= ' AND t.`tag_id` = :tag';
            $params['tag'] = $tag;
        }
        if ($group !== null) {
            $sql .= ' AND t.`group_id` = :grp';
            $params['grp'] = $group;
        }
        if ($text !== null && $text !== '') {
            $sql .= ' AND (t.`description` LIKE :txt OR t.`memo` LIKE :txt)';
            $params['txt'] = '%' . $text . '%';
        }
        if ($start !== null && $start !== '') {
            $sql .= ' AND t.`date` >= :start';
            $params['start'] = $start;
        }
        if ($end !== null && $end !== '') {
            $sql .= ' AND t.`date` <= :end';
            $params['end'] = $end;
        }

        $sql .= ' ORDER BY t.`date`';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve all transactions for a specific month and year.
     */
    public static function getByMonth(int $month, int $year): array {
        $db = Database::getConnection();
        $sql = 'SELECT t.`id`, t.`account_id`, t.`date`, t.`amount`, t.`description`, t.`memo`, '
             . 't.`category_id`, t.`tag_id`, t.`group_id`, t.`transfer_id`, '
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

    /**
     * Retrieve a single transaction by its ID including related names.
     */
    public static function get(int $id): ?array {
        $db = Database::getConnection();
        $sql = 'SELECT t.`id`, t.`account_id`, t.`date`, t.`amount`, t.`description`, t.`memo`, '
             . 't.`category_id`, t.`tag_id`, t.`group_id`, t.`transfer_id`, '
             . 'c.`name` AS category_name, tg.`name` AS tag_name, g.`name` AS group_name '
             . 'FROM `transactions` t '
             . 'LEFT JOIN `categories` c ON t.`category_id` = c.`id` '
             . 'LEFT JOIN `tags` tg ON t.`tag_id` = tg.`id` '
             . 'LEFT JOIN `transaction_groups` g ON t.`group_id` = g.`id` '
             . 'WHERE t.`id` = :id LIMIT 1';
        $stmt = $db->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    }

    /**
     * Update the tag of a specific transaction.
     */
    public static function setTag(int $transactionId, int $tagId): bool {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE `transactions` SET `tag_id` = :tag WHERE `id` = :id');
        return $stmt->execute(['tag' => $tagId, 'id' => $transactionId]);
    }

    /**
     * Update the category of a specific transaction.
     */
    public static function setCategory(int $transactionId, ?int $categoryId): bool {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE `transactions` SET `category_id` = :cat WHERE `id` = :id');
        return $stmt->execute(['cat' => $categoryId, 'id' => $transactionId]);
    }

    /**
     * Update the group of a specific transaction.
     */
    public static function setGroup(int $transactionId, ?int $groupId): bool {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE `transactions` SET `group_id` = :grp WHERE `id` = :id');
        return $stmt->execute(['grp' => $groupId, 'id' => $transactionId]);
    }

    /**
     * List months that have at least one transaction recorded.
     */
    public static function getAvailableMonths(): array {
        $db = Database::getConnection();
        $stmt = $db->query('SELECT DISTINCT YEAR(`date`) AS year, MONTH(`date`) AS month FROM `transactions` ORDER BY YEAR(`date`) DESC, MONTH(`date`) DESC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * List years that have at least one transaction recorded.
     */
    public static function getAvailableYears(): array {
        $db = Database::getConnection();
        $stmt = $db->query('SELECT DISTINCT YEAR(`date`) AS year FROM `transactions` ORDER BY YEAR(`date`)');
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
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
            WHERE YEAR(`date`) = :year AND `transfer_id` IS NULL
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
             WHERE MONTH(t.`date`) = :month AND YEAR(t.`date`) = :year AND t.`transfer_id` IS NULL'
        );
        $stmt->execute(['month' => $month, 'year' => $year]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $income = isset($row['income']) ? (float)$row['income'] : 0.0;
        $outgoings = isset($row['outgoings']) ? (float)$row['outgoings'] : 0.0;
        $delta = $income - $outgoings;
        return ['income' => $income, 'outgoings' => $outgoings, 'delta' => $delta];
    }

    /**
     * Retrieve total amounts by tag for a given month.
     * Returns tag name with totals including both positive and negative values ordered by total descending.
     */
    public static function getTagTotalsByMonth(int $month, int $year): array {
        $db = Database::getConnection();

        $dayCases = [];
        for ($d = 1; $d <= 31; $d++) {
            $dayCases[] = "SUM(CASE WHEN DAY(t.`date`) = $d THEN t.`amount` ELSE 0 END) AS `$d`";
        }

        $sql = 'SELECT CASE WHEN t.`tag_id` IS NULL THEN \'Not Categorised\' ELSE c.`name` END AS `category`, COALESCE(tg.`name`, \'Not Tagged\') AS `name`, '
             . implode(', ', $dayCases)
             . ', SUM(t.`amount`) AS `total`
             FROM `transactions` t
             LEFT JOIN `tags` tg ON t.`tag_id` = tg.`id`
             LEFT JOIN `categories` c ON t.`category_id` = c.`id`
             WHERE MONTH(t.`date`) = :month AND YEAR(t.`date`) = :year AND t.`transfer_id` IS NULL
             GROUP BY `category`, `name`
             ORDER BY `category`, `total` DESC';

        $stmt = $db->prepare($sql);
        $stmt->execute(['month' => $month, 'year' => $year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve total amounts by category for a given month.
     * Returns category name with positive and negative totals ordered by total descending.
     */
    public static function getCategoryTotalsByMonth(int $month, int $year): array {
        $db = Database::getConnection();

        $dayCases = [];
        for ($d = 1; $d <= 31; $d++) {
            $dayCases[] = "SUM(CASE WHEN DAY(t.`date`) = $d THEN t.`amount` ELSE 0 END) AS `$d`";
        }

        $sql = 'SELECT COALESCE(c.`name`, \'Not Categorised\') AS `name`, '
             . implode(', ', $dayCases)
             . ', SUM(t.`amount`) AS `total`
             FROM `transactions` t
             LEFT JOIN `categories` c ON t.`category_id` = c.`id`
             WHERE MONTH(t.`date`) = :month AND YEAR(t.`date`) = :year AND t.`transfer_id` IS NULL
             GROUP BY `name`
             ORDER BY `total` DESC';

        $stmt = $db->prepare($sql);
        $stmt->execute(['month' => $month, 'year' => $year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve total amounts by group for a given month.
     * Returns group name with positive and negative totals ordered by total descending.
     */
    public static function getGroupTotalsByMonth(int $month, int $year): array {
        $db = Database::getConnection();

        $dayCases = [];
        for ($d = 1; $d <= 31; $d++) {
            $dayCases[] = "SUM(CASE WHEN DAY(t.`date`) = $d THEN t.`amount` ELSE 0 END) AS `$d`";
        }

        $sql = 'SELECT COALESCE(g.`name`, \'Not Grouped\') AS `name`, '
             . implode(', ', $dayCases)
             . ', SUM(t.`amount`) AS `total`
             FROM `transactions` t
             LEFT JOIN `transaction_groups` g ON t.`group_id` = g.`id`
             WHERE MONTH(t.`date`) = :month AND YEAR(t.`date`) = :year AND t.`transfer_id` IS NULL
             GROUP BY `name`
             ORDER BY `total` DESC';

        $stmt = $db->prepare($sql);
        $stmt->execute(['month' => $month, 'year' => $year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**

     * Retrieve total amounts by tag for a given year.
     * Returns tag name with totals including both positive and negative values ordered by total descending.
     */
    public static function getTagTotalsByYear(int $year): array {
        $db = Database::getConnection();

        $monthCases = [];
        for ($m = 1; $m <= 12; $m++) {
            $monthCases[] = "SUM(CASE WHEN MONTH(t.`date`) = $m THEN t.`amount` ELSE 0 END) AS `$m`";
        }

        $sql = 'SELECT CASE WHEN t.`tag_id` IS NULL THEN \'Not Categorised\' ELSE c.`name` END AS `category`, COALESCE(tg.`name`, \'Not Tagged\') AS `name`, '
             . implode(', ', $monthCases)
             . ', SUM(t.`amount`) AS `total`
             FROM `transactions` t
             LEFT JOIN `tags` tg ON t.`tag_id` = tg.`id`
             LEFT JOIN `categories` c ON t.`category_id` = c.`id`
             WHERE YEAR(t.`date`) = :year AND t.`transfer_id` IS NULL
             GROUP BY `category`, `name`
             ORDER BY `category`, `total` DESC';

        $stmt = $db->prepare($sql);
        $stmt->execute(['year' => $year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve total amounts by category for a given year.
     * Returns category name with positive and negative totals ordered by total descending.
     */
    public static function getCategoryTotalsByYear(int $year): array {
        $db = Database::getConnection();

        $monthCases = [];
        for ($m = 1; $m <= 12; $m++) {
            $monthCases[] = "SUM(CASE WHEN MONTH(t.`date`) = $m THEN t.`amount` ELSE 0 END) AS `$m`";
        }

        $sql = 'SELECT COALESCE(c.`name`, \'Not Categorised\') AS `name`, '
             . implode(', ', $monthCases)
             . ', SUM(t.`amount`) AS `total`
             FROM `transactions` t
             LEFT JOIN `categories` c ON t.`category_id` = c.`id`
             WHERE YEAR(t.`date`) = :year AND t.`transfer_id` IS NULL
             GROUP BY `name`
            ORDER BY `total` DESC';

        $stmt = $db->prepare($sql);
        $stmt->execute(['year' => $year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve total amounts by group for a given year.
     * Returns group name with positive and negative totals ordered by total descending.
     */
    public static function getGroupTotalsByYear(int $year): array {
        $db = Database::getConnection();

        $monthCases = [];
        for ($m = 1; $m <= 12; $m++) {
            $monthCases[] = "SUM(CASE WHEN MONTH(t.`date`) = $m THEN t.`amount` ELSE 0 END) AS `$m`";
        }

        $sql = 'SELECT COALESCE(g.`name`, \'Not Grouped\') AS `name`, '
             . implode(', ', $monthCases)
             . ', SUM(t.`amount`) AS `total`
             FROM `transactions` t
             LEFT JOIN `transaction_groups` g ON t.`group_id` = g.`id`
             WHERE YEAR(t.`date`) = :year AND t.`transfer_id` IS NULL
             GROUP BY `name`
             ORDER BY `total` DESC';

        $stmt = $db->prepare($sql);
        $stmt->execute(['year' => $year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve tag totals across multiple years.
     */
    public static function getTagTotalsByYears(array $years): array {
        if (empty($years)) { return []; }
        $db = Database::getConnection();
        $yearCases = [];
        foreach ($years as $y) {
            $y = (int)$y;
            $yearCases[] = "SUM(CASE WHEN YEAR(t.`date`) = $y THEN t.`amount` ELSE 0 END) AS `$y`";
        }
        $sql = 'SELECT CASE WHEN t.`tag_id` IS NULL THEN \'Not Categorised\' ELSE c.`name` END AS `category`, COALESCE(tg.`name`, \'Not Tagged\') AS `name`, '
             . implode(', ', $yearCases)
               . ', SUM(t.`amount`) AS `total`'
               . ' FROM `transactions` t'
             . ' LEFT JOIN `tags` tg ON t.`tag_id` = tg.`id`'
             . ' LEFT JOIN `categories` c ON t.`category_id` = c.`id`'
             . ' WHERE t.`transfer_id` IS NULL'
             . ' GROUP BY `category`, `name`'
             . ' ORDER BY `category`, `total` DESC';
        $stmt = $db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve category totals across multiple years.
     */
    public static function getCategoryTotalsByYears(array $years): array {
        if (empty($years)) { return []; }
        $db = Database::getConnection();
        $yearCases = [];
        foreach ($years as $y) {
            $y = (int)$y;
            $yearCases[] = "SUM(CASE WHEN YEAR(t.`date`) = $y THEN t.`amount` ELSE 0 END) AS `$y`";
        }
        $sql = 'SELECT COALESCE(c.`name`, \'Not Categorised\') AS `name`, '
             . implode(', ', $yearCases)
             . ', SUM(t.`amount`) AS `total`
             FROM `transactions` t'
             . ' LEFT JOIN `categories` c ON t.`category_id` = c.`id`'
             . ' WHERE t.`transfer_id` IS NULL'
             . ' GROUP BY `name`'
             . ' ORDER BY `total` DESC';
        $stmt = $db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve group totals across multiple years.
     */
    public static function getGroupTotalsByYears(array $years): array {
        if (empty($years)) { return []; }
        $db = Database::getConnection();
        $yearCases = [];
        foreach ($years as $y) {
            $y = (int)$y;
            $yearCases[] = "SUM(CASE WHEN YEAR(t.`date`) = $y THEN t.`amount` ELSE 0 END) AS `$y`";
        }
        $sql = 'SELECT COALESCE(g.`name`, \'Not Grouped\') AS `name`, '
             . implode(', ', $yearCases)
             . ', SUM(t.`amount`) AS `total`
             FROM `transactions` t'
             . ' LEFT JOIN `transaction_groups` g ON t.`group_id` = g.`id`'
             . ' WHERE t.`transfer_id` IS NULL'
             . ' GROUP BY `name`'
             . ' ORDER BY `total` DESC';
        $stmt = $db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Search transactions across fields.
     * Supports partial matches for text fields and exact matches for numeric fields.
     */
    public static function search(?string $value, ?float $amount = null): array {
        $db = Database::getConnection();

        $sql = 'SELECT t.`id`, t.`account_id`, t.`date`, t.`amount`, t.`description`, t.`memo`, t.`transfer_id`, '
             . 'c.`name` AS category_name, tg.`name` AS tag_name, g.`name` AS group_name '
             . 'FROM `transactions` t '
             . 'LEFT JOIN `categories` c ON t.`category_id` = c.`id` '
             . 'LEFT JOIN `tags` tg ON t.`tag_id` = tg.`id` '
             . 'LEFT JOIN `transaction_groups` g ON t.`group_id` = g.`id`';

        $conditions = [];
        $params = [];

        if ($value !== null && $value !== '') {
            $conditions[] = '(t.`description` LIKE :val'
                . ' OR t.`memo` LIKE :val'
                . ' OR t.`date` LIKE :val'
                . ' OR t.`ofx_id` LIKE :val'
                . ' OR c.`name` LIKE :val'
                . ' OR tg.`name` LIKE :val'
                . ' OR g.`name` LIKE :val)';
            $params['val'] = '%' . $value . '%';

            if (is_numeric($value)) {
                $conditions[] = '(t.`id` = :num'
                    . ' OR t.`account_id` = :num'
                    . ' OR t.`category_id` = :num'
                    . ' OR t.`tag_id` = :num'
                    . ' OR t.`group_id` = :num'
                    . ' OR t.`amount` = :num)';
                $params['num'] = $value;
            }
        }

        if ($amount !== null) {
            $conditions[] = 't.`amount` = :amount';
            $params['amount'] = $amount;
        }

        if ($conditions) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve all transactions linked as transfers, returned as pairs.
     */
    public static function getTransfers(): array {
        $db = Database::getConnection();
        $sql = 'SELECT t.`id`, t.`account_id`, a.`name` AS account_name, t.`date`, '
             . 't.`amount`, t.`description`, t.`transfer_id`
             FROM `transactions` t '
             . 'JOIN `accounts` a ON t.`account_id` = a.`id`
             WHERE t.`transfer_id` IS NOT NULL '
             . 'ORDER BY t.`transfer_id`, t.`id`';
        $stmt = $db->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($rows as $row) {
            $tid = $row['transfer_id'];
            if (!isset($grouped[$tid])) {
                $grouped[$tid] = [];
            }
            $grouped[$tid][] = $row;
        }

        $result = [];
        foreach ($grouped as $tid => $pair) {
            if (count($pair) === 2) {
                $from = $pair[0]['amount'] < 0 ? $pair[0] : $pair[1];
                $to   = $pair[0]['amount'] < 0 ? $pair[1] : $pair[0];
                $result[] = [
                    'transfer_id' => (int)$tid,
                    'date' => $from['date'],
                    'description' => $from['description'],
                    'amount' => abs((float)$from['amount']),
                    'from_id' => (int)$from['id'],
                    'from_account' => $from['account_name'],
                    'to_id' => (int)$to['id'],
                    'to_account' => $to['account_name']
                ];
            }
        }

        return $result;
    }

    /**
     * Link two existing transactions as a transfer pair.
     */
    public static function linkTransfer(int $id1, int $id2): bool {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT `id`, `amount` FROM `transactions` WHERE `id` IN (?, ?)');
        $stmt->execute([$id1, $id2]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 2) {
            return false;
        }
        if ((float)$rows[0]['amount'] !== -(float)$rows[1]['amount']) {
            return false;
        }
        $tid = min($id1, $id2);
        $upd = $db->prepare('UPDATE `transactions` SET `transfer_id` = :tid WHERE `id` IN (:a, :b)');
        return $upd->execute(['tid' => $tid, 'a' => $id1, 'b' => $id2]);
    }

    /**
     * Return descriptions of untagged transactions with occurrence counts and totals.
     * Results are ordered by most common description first.
     */
    public static function getUntaggedCounts(): array {
        $db = Database::getConnection();
        $sql = 'SELECT `description`, `memo`, COUNT(*) AS `count`, SUM(`amount`) AS `total` '
             . 'FROM `transactions` WHERE `tag_id` IS NULL '
             . 'GROUP BY `description`, `memo` ORDER BY `count` DESC';
        $stmt = $db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
