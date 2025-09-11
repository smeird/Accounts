<?php
// Model representing financial transactions and related queries.
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/Tag.php';
require_once __DIR__ . '/Log.php';

class Transaction {
    const DESC_MAX_LENGTH = 255;
    const MEMO_MAX_LENGTH = 255;
    const ID_MAX_LENGTH = 255;
    const TYPE_MAX_LENGTH = 50;
    const REF_MAX_LENGTH = 32;
    const CHECK_MAX_LENGTH = 20;
    /**
     * Insert a new transaction and attempt to auto-tag and link transfers.
     */
    public static function create(int $account, string $date, float $amount, string $description, ?string $memo = null, ?int $category = null, ?int $tag = null, ?int $group = null, ?string $ofx_id = null, ?string $ofx_type = null, ?string $bank_ofx_id = null): int {
        if ($tag === null) {
            $tag = Tag::findMatch($description);
            if ($tag === null && $ofx_type === 'INT') {
                $tag = Tag::getInterestChargeId();
            }
        }
        $db = Database::getConnection();

        $substr = function_exists('mb_substr') ? 'mb_substr' : 'substr';
        $description = $substr($description, 0, self::DESC_MAX_LENGTH);
        $memo = $memo === null ? null : $substr($memo, 0, self::MEMO_MAX_LENGTH);
        $ofx_id = $ofx_id === null ? null : $substr($ofx_id, 0, self::ID_MAX_LENGTH);
        $ofx_type = $ofx_type === null ? null : $substr($ofx_type, 0, self::TYPE_MAX_LENGTH);
        $bank_ofx_id = $bank_ofx_id === null ? null : $substr($bank_ofx_id, 0, self::ID_MAX_LENGTH);

        // avoid duplicate inserts when an OFX id already exists
        if ($ofx_id !== null) {
            $check = $db->prepare('SELECT id FROM `transactions` WHERE `ofx_id` = :oid LIMIT 1');
            $check->execute(['oid' => $ofx_id]);
            if ($check->fetch(PDO::FETCH_ASSOC)) {
                return 0;
            }
        }


        // Secondary duplicate check using bank-provided FITID. Exact duplicates
        // are silently skipped, but conflicting details are logged for review.
        if ($bank_ofx_id !== null) {
            $dupCheck = $db->prepare(
                'SELECT id, date, amount, description, IFNULL(memo, "") AS memo '
                . 'FROM `transactions` WHERE `account_id` = :account AND `bank_ofx_id` = :boid LIMIT 1'
            );
            $dupCheck->execute([
                'account' => $account,
                'boid' => $bank_ofx_id
            ]);
            if ($row = $dupCheck->fetch(PDO::FETCH_ASSOC)) {
                if ($row['date'] != $date || (float)$row['amount'] != $amount
                    || strtoupper(trim($row['description'])) !== strtoupper(trim($description))
                    || strtoupper(trim($row['memo'])) !== strtoupper(trim($memo ?? ''))) {
                    Log::write("FITID $bank_ofx_id conflict for account $account", 'WARNING');
                }
                return 0;
            }
        }

        // Fallback duplicate check on core fields when no OFX identifiers are available.
        // Ignore memo differences and normalise description to prevent near-identical duplicates.
        $coreCheck = $db->prepare(
            'SELECT id FROM `transactions` '
            . 'WHERE `account_id` = :account AND `date` = :date AND `amount` = :amount '
            . 'AND UPPER(TRIM(`description`)) = UPPER(TRIM(:description)) '
            . 'LIMIT 1'
        );
        $coreCheck->execute([
            'account' => $account,
            'date' => $date,
            'amount' => $amount,
            'description' => $description
        ]);
        if ($coreCheck->fetch(PDO::FETCH_ASSOC)) {
            return 0;
        }

        // Collapse pending vs posted duplicates by checking for matching amount and
        // description within a small date window.
        $start = date('Y-m-d', strtotime($date . ' -3 days'));
        $end   = date('Y-m-d', strtotime($date . ' +3 days'));
        $nearCheck = $db->prepare(
            'SELECT id FROM `transactions` '
            . 'WHERE `account_id` = :account AND `amount` = :amount '
            . 'AND UPPER(TRIM(`description`)) = UPPER(TRIM(:description)) '
            . 'AND `date` BETWEEN :start AND :end LIMIT 1'
        );
        $nearCheck->execute([
            'account' => $account,
            'amount' => $amount,
            'description' => $description,
            'start' => $start,
            'end' => $end
        ]);
        if ($nearCheck->fetch(PDO::FETCH_ASSOC)) {
            return 0;
        }


        $stmt = $db->prepare('INSERT INTO transactions (`account_id`, `date`, `amount`, `description`, `memo`, `category_id`, `tag_id`, `group_id`, `ofx_id`, `ofx_type`, `bank_ofx_id`) VALUES (:account, :date, :amount, :description, :memo, :category, :tag, :group, :ofx_id, :ofx_type, :bank_ofx_id)');
        $stmt->execute([
            'account' => $account,
            'date' => $date,
            'amount' => $amount,
            'description' => $description,
            'memo' => $memo,
            'category' => $category,
            'tag' => $tag,
            'group' => $group,
            'ofx_id' => $ofx_id,
            'ofx_type' => $ofx_type,
            'bank_ofx_id' => $bank_ofx_id
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
        } elseif ($ofx_type === 'XFER') {
            $upd = $db->prepare('UPDATE transactions SET transfer_id = :tid WHERE id = :id');
            $upd->execute(['tid' => $id, 'id' => $id]);
        }

        return $id;
    }


    /**
     * Return transactions for a given category excluding transfers.
     */
    public static function getByCategory(int $categoryId): array {
        $db = Database::getConnection();
        $ignore = Tag::getIgnoreId();
        $sql = 'SELECT t.`date`, t.`amount`, t.`description`, '
             . 'c.`name` AS category_name, s.`name` AS segment_name, tg.`name` AS tag_name, g.`name` AS group_name '
             . 'FROM `transactions` t '
             . 'LEFT JOIN `categories` c ON t.`category_id` = c.`id` '
             . 'LEFT JOIN `segments` s ON t.`segment_id` = s.`id` '
             . 'LEFT JOIN `tags` tg ON t.`tag_id` = tg.`id` '
             . 'LEFT JOIN `transaction_groups` g ON t.`group_id` = g.`id` '
             . 'WHERE t.`category_id` = :category AND t.`transfer_id` IS NULL'
             . ' AND (t.`tag_id` IS NULL OR t.`tag_id` != :ignore)';
        $stmt = $db->prepare($sql);
        $stmt->execute(['category' => $categoryId, 'ignore' => $ignore]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return transactions for a given tag excluding transfers.
     */
    public static function getByTag(int $tagId): array {
        $db = Database::getConnection();
        $ignore = Tag::getIgnoreId();
        $sql = 'SELECT t.`date`, t.`amount`, t.`description`, '
             . 'c.`name` AS category_name, s.`name` AS segment_name, tg.`name` AS tag_name, g.`name` AS group_name '
             . 'FROM `transactions` t '
             . 'LEFT JOIN `categories` c ON t.`category_id` = c.`id` '
             . 'LEFT JOIN `segments` s ON t.`segment_id` = s.`id` '
             . 'LEFT JOIN `tags` tg ON t.`tag_id` = tg.`id` '
             . 'LEFT JOIN `transaction_groups` g ON t.`group_id` = g.`id` '
             . 'WHERE t.`tag_id` = :tag AND t.`transfer_id` IS NULL'
             . ' AND (t.`tag_id` IS NULL OR t.`tag_id` != :ignore)';
        $stmt = $db->prepare($sql);
        $stmt->execute(['tag' => $tagId, 'ignore' => $ignore]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return transactions for a given group excluding transfers.
     */
    public static function getByGroup(int $groupId): array {
        $db = Database::getConnection();
        $ignore = Tag::getIgnoreId();
        $sql = 'SELECT t.`date`, t.`amount`, t.`description`, '
             . 'c.`name` AS category_name, s.`name` AS segment_name, tg.`name` AS tag_name, g.`name` AS group_name '
             . 'FROM `transactions` t '
             . 'LEFT JOIN `categories` c ON t.`category_id` = c.`id` '
             . 'LEFT JOIN `segments` s ON t.`segment_id` = s.`id` '
             . 'LEFT JOIN `tags` tg ON t.`tag_id` = tg.`id` '
             . 'LEFT JOIN `transaction_groups` g ON t.`group_id` = g.`id` '
             . 'WHERE t.`group_id` = :grp AND t.`transfer_id` IS NULL'
             . ' AND (t.`tag_id` IS NULL OR t.`tag_id` != :ignore)';
        $stmt = $db->prepare($sql);
        $stmt->execute(['grp' => $groupId, 'ignore' => $ignore]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Filter transactions by optional category, tag, group, segment, text and date range.
     */
    public static function filter($category = null, $tag = null, $group = null, $segment = null, ?string $text = null, ?string $memo = null, ?string $start = null, ?string $end = null): array {
        if ($category === null && $tag === null && $group === null && $segment === null && $text === null && $memo === null && $start === null && $end === null) {
            return [];
        }

        $db = Database::getConnection();
        $ignore = Tag::getIgnoreId();
        $sql = 'SELECT t.`id`, t.`date`, t.`amount`, t.`description`, t.`memo`, '
             . 'c.`name` AS category_name, tg.`name` AS tag_name, g.`name` AS group_name, s.`name` AS segment_name '
             . 'FROM `transactions` t '
             . 'LEFT JOIN `categories` c ON t.`category_id` = c.`id` '
             . 'LEFT JOIN `tags` tg ON t.`tag_id` = tg.`id` '
             . 'LEFT JOIN `transaction_groups` g ON t.`group_id` = g.`id` '
             . 'LEFT JOIN `segments` s ON t.`segment_id` = s.`id` '
             . 'WHERE t.`transfer_id` IS NULL'
             . ' AND (t.`tag_id` IS NULL OR t.`tag_id` != :ignore)';

        $params = [];
        $addIn = function($values, $column, $prefix) use (&$sql, &$params) {
            if (is_array($values) && !empty($values)) {
                $ph = [];
                foreach ($values as $i => $val) {
                    $key = $prefix . $i;
                    $ph[] = ':' . $key;
                    $params[$key] = $val;
                }
                $sql .= ' AND t.`' . $column . '` IN (' . implode(',', $ph) . ')';
            } elseif ($values !== null) {
                $sql .= ' AND t.`' . $column . '` = :' . $prefix;
                $params[$prefix] = $values;
            }
        };
        $addIn($category, 'category_id', 'category');
        $addIn($tag, 'tag_id', 'tag');
        $addIn($group, 'group_id', 'grp');
        $addIn($segment, 'segment_id', 'segment');
        if ($text !== null && $text !== '') {
            $sql .= ' AND t.`description` LIKE :txt';
            $params['txt'] = '%' . $text . '%';
        }
        if ($memo !== null && $memo !== '') {
            $sql .= ' AND t.`memo` LIKE :memo';
            $params['memo'] = '%' . $memo . '%';
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
        $params['ignore'] = $ignore;
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve all transactions for a specific month and year.
     * Optionally limit results to only untagged transactions.
     */
    public static function getByMonth(int $month, int $year, bool $onlyUntagged = false): array {
        $db = Database::getConnection();
        $ignore = Tag::getIgnoreId();
        $sql = 'SELECT t.`id`, t.`account_id`, t.`date`, t.`amount`, t.`description`, t.`memo`, '
             . 't.`category_id`, t.`tag_id`, t.`group_id`, t.`transfer_id`, '
             . 'c.`name` AS category_name, s.`name` AS segment_name, tg.`name` AS tag_name, g.`name` AS group_name '
             . 'FROM `transactions` t '
             . 'LEFT JOIN `categories` c ON t.`category_id` = c.`id` '
             . 'LEFT JOIN `segments` s ON t.`segment_id` = s.`id` '
             . 'LEFT JOIN `tags` tg ON t.`tag_id` = tg.`id` '
             . 'LEFT JOIN `transaction_groups` g ON t.`group_id` = g.`id` '
             . 'WHERE MONTH(t.`date`) = :month AND YEAR(t.`date`) = :year '
             . 'AND (t.`tag_id` IS NULL OR t.`tag_id` != :ignore)';
        if ($onlyUntagged) {
            $sql .= ' AND t.`tag_id` IS NULL';
        }
        $sql .= ' ORDER BY t.`date`';
        $stmt = $db->prepare($sql);
        $stmt->execute(['month' => $month, 'year' => $year, 'ignore' => $ignore]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve all transactions for a specific account ordered by date.
     */
    public static function getByAccount(int $accountId): array {
        $db = Database::getConnection();
        $ignore = Tag::getIgnoreId();
        $sql = 'SELECT t.`id`, t.`date`, t.`amount`, t.`description`, t.`memo`, '
             . 'c.`name` AS category_name, s.`name` AS segment_name, tg.`name` AS tag_name, g.`name` AS group_name '
             . 'FROM `transactions` t '
             . 'LEFT JOIN `categories` c ON t.`category_id` = c.`id` '
             . 'LEFT JOIN `segments` s ON t.`segment_id` = s.`id` '
             . 'LEFT JOIN `tags` tg ON t.`tag_id` = tg.`id` '
             . 'LEFT JOIN `transaction_groups` g ON t.`group_id` = g.`id` '
             . 'WHERE t.`account_id` = :acc '
             . 'AND (t.`tag_id` IS NULL OR t.`tag_id` != :ignore) '
             . 'ORDER BY t.`date` DESC, t.`id` DESC';
        $stmt = $db->prepare($sql);
        $stmt->execute(['acc' => $accountId, 'ignore' => $ignore]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve transactions between two dates inclusive.
     */
    public static function getByDateRange(string $start, string $end): array {
        $db = Database::getConnection();
        $ignore = Tag::getIgnoreId();
        $sql = 'SELECT t.`id`, t.`account_id`, a.`name` AS account_name, t.`date`, t.`amount`, t.`description`, t.`memo`, '
             . 'c.`name` AS category_name, s.`name` AS segment_name, tg.`name` AS tag_name, g.`name` AS group_name '
             . 'FROM `transactions` t '
             . 'LEFT JOIN `accounts` a ON t.`account_id` = a.`id` '
             . 'LEFT JOIN `categories` c ON t.`category_id` = c.`id` '
             . 'LEFT JOIN `segments` s ON t.`segment_id` = s.`id` '
             . 'LEFT JOIN `tags` tg ON t.`tag_id` = tg.`id` '
             . 'LEFT JOIN `transaction_groups` g ON t.`group_id` = g.`id` '
             . 'WHERE t.`date` BETWEEN :start AND :end '
             . 'AND (t.`tag_id` IS NULL OR t.`tag_id` != :ignore) '
             . 'ORDER BY t.`date`';
        $stmt = $db->prepare($sql);
        $stmt->execute(['start' => $start, 'end' => $end, 'ignore' => $ignore]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve a single transaction by its ID including related names.
     */
    public static function get(int $id): ?array {
        $db = Database::getConnection();
        $sql = 'SELECT t.`id`, t.`account_id`, t.`date`, t.`amount`, t.`description`, t.`memo`, '
             . 't.`category_id`, t.`tag_id`, t.`group_id`, t.`transfer_id`, t.`ofx_type`, '
             . 't.`ofx_id`, t.`bank_ofx_id`, '
             . 'a.`name` AS account_name, a.`sort_code`, a.`account_number`, '
             . 'c.`name` AS category_name, s.`name` AS segment_name, tg.`name` AS tag_name, g.`name` AS group_name '
             . 'FROM `transactions` t '
             . 'LEFT JOIN `accounts` a ON t.`account_id` = a.`id` '
             . 'LEFT JOIN `categories` c ON t.`category_id` = c.`id` '
             . 'LEFT JOIN `segments` s ON t.`segment_id` = s.`id` '
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
    public static function setTag(int $transactionId, ?int $tagId): bool {
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
        $ignore = Tag::getIgnoreId();
        $stmt = $db->prepare('SELECT DISTINCT YEAR(`date`) AS year, MONTH(`date`) AS month FROM `transactions` WHERE `tag_id` IS NULL OR `tag_id` != :ignore ORDER BY YEAR(`date`) DESC, MONTH(`date`) DESC');
        $stmt->execute(['ignore' => $ignore]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * List years that have at least one transaction recorded.
     */
    public static function getAvailableYears(): array {
        $db = Database::getConnection();
        $ignore = Tag::getIgnoreId();
        $stmt = $db->prepare('SELECT DISTINCT YEAR(`date`) AS year FROM `transactions` WHERE `tag_id` IS NULL OR `tag_id` != :ignore ORDER BY YEAR(`date`)');
        $stmt->execute(['ignore' => $ignore]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Retrieve total income and outgoings for each month of a given year.
     * Amounts are returned as positive numbers and months with no activity will have totals of 0.
     */
    public static function getMonthlySpending(int $year): array {
        $db = Database::getConnection();
        $ignore = Tag::getIgnoreId();
        $stmt = $db->prepare('SELECT MONTH(`date`) AS `month`,
            SUM(CASE WHEN `amount` > 0 THEN `amount` ELSE 0 END) AS `income`,
            SUM(CASE WHEN `amount` < 0 THEN -`amount` ELSE 0 END) AS `spent`
            FROM `transactions`
            WHERE YEAR(`date`) = :year AND `transfer_id` IS NULL AND (`tag_id` IS NULL OR `tag_id` != :ignore)
            GROUP BY MONTH(`date`)
            ORDER BY MONTH(`date`)');
        $stmt->execute(['year' => $year, 'ignore' => $ignore]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Ensure all months are present in the result
        $result = [];
        for ($m = 1; $m <= 12; $m++) {
            $result[$m] = ['income' => 0.0, 'spent' => 0.0];
        }
        foreach ($rows as $row) {
            $month = (int)$row['month'];
            $result[$month] = [
                'income' => isset($row['income']) ? (float)$row['income'] : 0.0,
                'spent' => isset($row['spent']) ? (float)$row['spent'] : 0.0,
            ];
        }

        $output = [];
        foreach ($result as $month => $vals) {
            $output[] = ['month' => $month, 'income' => $vals['income'], 'spent' => $vals['spent']];
        }
        return $output;
    }

    /**
     * Retrieve income and outgoings totals for a given month.
     * Returns income and outgoings as positive numbers with delta calculated.
     */
    public static function getMonthlyTotals(int $month, int $year): array {
        $db = Database::getConnection();
        $ignore = Tag::getIgnoreId();
        $stmt = $db->prepare(
            'SELECT
                SUM(CASE WHEN t.`amount` > 0 THEN t.`amount` ELSE 0 END) AS income,
                SUM(CASE WHEN t.`amount` < 0 THEN -t.`amount` ELSE 0 END) AS outgoings
             FROM `transactions` t
             WHERE MONTH(t.`date`) = :month AND YEAR(t.`date`) = :year AND t.`transfer_id` IS NULL AND (t.`tag_id` IS NULL OR t.`tag_id` != :ignore)'
        );
        $stmt->execute(['month' => $month, 'year' => $year, 'ignore' => $ignore]);
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

        $ignore = Tag::getIgnoreId();
        $sql = 'SELECT CASE WHEN t.`tag_id` IS NULL THEN \'Not Categorised\' ELSE c.`name` END AS `category`, COALESCE(tg.`name`, \'Not Tagged\') AS `name`, '
             . implode(', ', $dayCases)
             . ', SUM(t.`amount`) AS `total`
             FROM `transactions` t
             LEFT JOIN `tags` tg ON t.`tag_id` = tg.`id`
             LEFT JOIN `categories` c ON t.`category_id` = c.`id`
             WHERE MONTH(t.`date`) = :month AND YEAR(t.`date`) = :year AND t.`transfer_id` IS NULL AND (t.`tag_id` IS NULL OR t.`tag_id` != :ignore)
             GROUP BY `category`, `name`
             ORDER BY `category`, `total` DESC';

        $stmt = $db->prepare($sql);
        $stmt->execute(['month' => $month, 'year' => $year, 'ignore' => $ignore]);
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

        $ignore = Tag::getIgnoreId();
        $sql = 'SELECT COALESCE(c.`name`, \'Not Categorised\') AS `name`, '
             . 's.`name` AS `segment_name`, '
             . implode(', ', $dayCases)
             . ', SUM(t.`amount`) AS `total`
             FROM `transactions` t
             LEFT JOIN `categories` c ON t.`category_id` = c.`id`
             LEFT JOIN `segments` s ON c.`segment_id` = s.`id`
             WHERE MONTH(t.`date`) = :month AND YEAR(t.`date`) = :year AND t.`transfer_id` IS NULL AND (t.`tag_id` IS NULL OR t.`tag_id` != :ignore)
             GROUP BY `name`, `segment_name`
             ORDER BY `total` DESC';

        $stmt = $db->prepare($sql);
        $stmt->execute(['month' => $month, 'year' => $year, 'ignore' => $ignore]);
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

        $ignore = Tag::getIgnoreId();
        $sql = 'SELECT COALESCE(g.`name`, \'Not Grouped\') AS `name`, '
             . implode(', ', $dayCases)
             . ', SUM(t.`amount`) AS `total`
             FROM `transactions` t
             LEFT JOIN `transaction_groups` g ON t.`group_id` = g.`id`
             WHERE MONTH(t.`date`) = :month AND YEAR(t.`date`) = :year AND t.`transfer_id` IS NULL AND (t.`tag_id` IS NULL OR t.`tag_id` != :ignore)
             GROUP BY `name`
             ORDER BY `total` DESC';

        $stmt = $db->prepare($sql);
        $stmt->execute(['month' => $month, 'year' => $year, 'ignore' => $ignore]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve total amounts by segment for a given month.

     * Returns segment name with totals by day and overall.

     */
    public static function getSegmentTotalsByMonth(int $month, int $year): array {
        $db = Database::getConnection();

        $dayCases = [];
        for ($d = 1; $d <= 31; $d++) {
            $dayCases[] = "SUM(CASE WHEN DAY(t.`date`) = $d THEN t.`amount` ELSE 0 END) AS `$d`";
        }

        $ignore = Tag::getIgnoreId();
        $sql = 'SELECT COALESCE(s.`name`, \'Not Segmented\') AS `name`, '
             . implode(', ', $dayCases)
             . ', SUM(t.`amount`) AS `total`'
             . ' FROM `transactions` t'

             . ' LEFT JOIN `segments` s ON t.`segment_id` = s.`id`'
             . ' WHERE MONTH(t.`date`) = :month AND YEAR(t.`date`) = :year'
             . ' AND t.`transfer_id` IS NULL AND (t.`tag_id` IS NULL OR t.`tag_id` != :ignore)'

             . ' GROUP BY `name`'
             . ' ORDER BY `total` DESC';

        $stmt = $db->prepare($sql);
        $stmt->execute(['month' => $month, 'year' => $year, 'ignore' => $ignore]);
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

        $ignore = Tag::getIgnoreId();
        $sql = 'SELECT CASE WHEN t.`tag_id` IS NULL THEN \'Not Categorised\' ELSE c.`name` END AS `category`, COALESCE(tg.`name`, \'Not Tagged\') AS `name`, '
             . implode(', ', $monthCases)
             . ', SUM(t.`amount`) AS `total`
             FROM `transactions` t
             LEFT JOIN `tags` tg ON t.`tag_id` = tg.`id`
             LEFT JOIN `categories` c ON t.`category_id` = c.`id`
             WHERE YEAR(t.`date`) = :year AND t.`transfer_id` IS NULL AND (t.`tag_id` IS NULL OR t.`tag_id` != :ignore)
             GROUP BY `category`, `name`
             ORDER BY `category`, `total` DESC';

        $stmt = $db->prepare($sql);
        $stmt->execute(['year' => $year, 'ignore' => $ignore]);
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

        $ignore = Tag::getIgnoreId();
        $sql = 'SELECT COALESCE(c.`name`, \'Not Categorised\') AS `name`, '
             . 's.`name` AS `segment_name`, '
             . implode(', ', $monthCases)
             . ', SUM(t.`amount`) AS `total`
             FROM `transactions` t
             LEFT JOIN `categories` c ON t.`category_id` = c.`id`
             LEFT JOIN `segments` s ON c.`segment_id` = s.`id`
             WHERE YEAR(t.`date`) = :year AND t.`transfer_id` IS NULL AND (t.`tag_id` IS NULL OR t.`tag_id` != :ignore)
             GROUP BY `name`, `segment_name`
             ORDER BY `total` DESC';

        $stmt = $db->prepare($sql);
        $stmt->execute(['year' => $year, 'ignore' => $ignore]);
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

        $ignore = Tag::getIgnoreId();
        $sql = 'SELECT COALESCE(g.`name`, \'Not Grouped\') AS `name`, '
             . implode(', ', $monthCases)
             . ', SUM(t.`amount`) AS `total`
             FROM `transactions` t
             LEFT JOIN `transaction_groups` g ON t.`group_id` = g.`id`
             WHERE YEAR(t.`date`) = :year AND t.`transfer_id` IS NULL AND (t.`tag_id` IS NULL OR t.`tag_id` != :ignore)
             GROUP BY `name`
             ORDER BY `total` DESC';

        $stmt = $db->prepare($sql);
        $stmt->execute(['year' => $year, 'ignore' => $ignore]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve total amounts by segment for a given year.

     * Returns segment name with totals by month and overall.

     */
    public static function getSegmentTotalsByYear(int $year): array {
        $db = Database::getConnection();

        $monthCases = [];
        for ($m = 1; $m <= 12; $m++) {
            $monthCases[] = "SUM(CASE WHEN MONTH(t.`date`) = $m THEN t.`amount` ELSE 0 END) AS `$m`";
        }

        $ignore = Tag::getIgnoreId();
        $sql = 'SELECT COALESCE(s.`name`, \'Not Segmented\') AS `name`, '
             . implode(', ', $monthCases)
             . ', SUM(t.`amount`) AS `total`'
             . ' FROM `transactions` t'

             . ' LEFT JOIN `segments` s ON t.`segment_id` = s.`id`'
             . ' WHERE YEAR(t.`date`) = :year AND t.`transfer_id` IS NULL'
             . ' AND (t.`tag_id` IS NULL OR t.`tag_id` != :ignore)'

             . ' GROUP BY `name`'
             . ' ORDER BY `total` DESC';

        $stmt = $db->prepare($sql);
        $stmt->execute(['year' => $year, 'ignore' => $ignore]);
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
        $ignore = Tag::getIgnoreId();
        $sql = 'SELECT CASE WHEN t.`tag_id` IS NULL THEN \'Not Categorised\' ELSE c.`name` END AS `category`, COALESCE(tg.`name`, \'Not Tagged\') AS `name`, '
             . implode(', ', $yearCases)
               . ', SUM(t.`amount`) AS `total`'
               . ' FROM `transactions` t'
             . ' LEFT JOIN `tags` tg ON t.`tag_id` = tg.`id`'
             . ' LEFT JOIN `categories` c ON t.`category_id` = c.`id`'
             . ' WHERE t.`transfer_id` IS NULL AND (t.`tag_id` IS NULL OR t.`tag_id` != :ignore)'
             . ' GROUP BY `category`, `name`'
             . ' ORDER BY `category`, `total` DESC';
        $stmt = $db->prepare($sql);
        $stmt->execute(['ignore' => $ignore]);
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
        $ignore = Tag::getIgnoreId();
        $sql = 'SELECT COALESCE(c.`name`, \'Not Categorised\') AS `name`, '
             . 's.`name` AS `segment_name`, '
             . implode(', ', $yearCases)
             . ', SUM(t.`amount`) AS `total`
             FROM `transactions` t'
             . ' LEFT JOIN `categories` c ON t.`category_id` = c.`id`'
             . ' LEFT JOIN `segments` s ON c.`segment_id` = s.`id`'
             . ' WHERE t.`transfer_id` IS NULL AND (t.`tag_id` IS NULL OR t.`tag_id` != :ignore)'
             . ' GROUP BY `name`, `segment_name`'
             . ' ORDER BY `total` DESC';
        $stmt = $db->prepare($sql);
        $stmt->execute(['ignore' => $ignore]);
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
        $ignore = Tag::getIgnoreId();
        $sql = 'SELECT COALESCE(g.`name`, \'Not Grouped\') AS `name`, '
             . implode(', ', $yearCases)
             . ', SUM(t.`amount`) AS `total`
             FROM `transactions` t'
             . ' LEFT JOIN `transaction_groups` g ON t.`group_id` = g.`id`'
             . ' WHERE t.`transfer_id` IS NULL AND (t.`tag_id` IS NULL OR t.`tag_id` != :ignore)'
             . ' GROUP BY `name`'
             . ' ORDER BY `total` DESC';
        $stmt = $db->prepare($sql);
        $stmt->execute(['ignore' => $ignore]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve segment totals across multiple years.
     */
    public static function getSegmentTotalsByYears(array $years): array {
        if (empty($years)) { return []; }
        $db = Database::getConnection();
        $yearCases = [];
        foreach ($years as $y) {
            $y = (int)$y;
            $yearCases[] = "SUM(CASE WHEN YEAR(t.`date`) = $y THEN t.`amount` ELSE 0 END) AS `$y`";
        }
        $ignore = Tag::getIgnoreId();
        $sql = 'SELECT COALESCE(s.`name`, \'Not Segmented\') AS `name`, '
             . implode(', ', $yearCases)
             . ', SUM(t.`amount`) AS `total`'
             . ' FROM `transactions` t'

             . ' LEFT JOIN `segments` s ON t.`segment_id` = s.`id`'

             . ' WHERE t.`transfer_id` IS NULL AND (t.`tag_id` IS NULL OR t.`tag_id` != :ignore)'
             . ' GROUP BY `name`'
             . ' ORDER BY `total` DESC';
        $stmt = $db->prepare($sql);
        $stmt->execute(['ignore' => $ignore]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Search transactions across fields.
     * Supports partial matches for text fields and numeric range searches for the amount field.
     */
    public static function search(?string $value, ?float $minAmount = null, ?float $maxAmount = null): array {
        $db = Database::getConnection();

        $sql = 'SELECT t.`id`, t.`account_id`, t.`date`, t.`amount`, t.`description`, t.`memo`, t.`transfer_id`, '
             . 'c.`name` AS category_name, s.`name` AS segment_name, tg.`name` AS tag_name, g.`name` AS group_name '
             . 'FROM `transactions` t '
             . 'LEFT JOIN `categories` c ON t.`category_id` = c.`id` '
             . 'LEFT JOIN `segments` s ON t.`segment_id` = s.`id` '
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
                . ' OR s.`name` LIKE :val'
                . ' OR tg.`name` LIKE :val'
                . ' OR g.`name` LIKE :val)';
            $params['val'] = '%' . $value . '%';

            if (is_numeric($value)) {
                $conditions[] = '(t.`id` = :num'
                    . ' OR t.`account_id` = :num'
                    . ' OR t.`category_id` = :num'
                    . ' OR t.`segment_id` = :num'
                    . ' OR t.`tag_id` = :num'
                    . ' OR t.`group_id` = :num'
                    . ' OR t.`amount` = :num)';
                $params['num'] = $value;
            }
        }

        if ($minAmount !== null && $maxAmount !== null) {
            $conditions[] = 't.`amount` BETWEEN :min_amount AND :max_amount';
            $params['min_amount'] = $minAmount;
            $params['max_amount'] = $maxAmount;
        } elseif ($minAmount !== null) {
            $conditions[] = 't.`amount` >= :min_amount';
            $params['min_amount'] = $minAmount;
        } elseif ($maxAmount !== null) {
            $conditions[] = 't.`amount` <= :max_amount';
            $params['max_amount'] = $maxAmount;
        }

        $ignore = Tag::getIgnoreId();
        $conditions[] = '(t.`tag_id` IS NULL OR t.`tag_id` != :ignore)';
        $params['ignore'] = $ignore;
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
             . 't.`amount`, t.`description`, t.`transfer_id` '
             . 'FROM `transactions` t '
             . 'JOIN `accounts` a ON t.`account_id` = a.`id` '
             . 'WHERE t.`transfer_id` IS NOT NULL '
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
                    'transfer_id'      => (int)$tid,
                    'date'             => $from['date'],
                    'from_id'          => (int)$from['id'],
                    'from_account'     => $from['account_name'],
                    'from_amount'      => (float)$from['amount'],
                    'from_description' => $from['description'],
                    'to_id'            => (int)$to['id'],
                    'to_account'       => $to['account_name'],
                    'to_amount'        => (float)$to['amount'],
                    'to_description'   => $to['description']
                ];
            }
        }

        return $result;
    }

    /**
     * Retrieve transactions marked as transfers in the imported OFX data.
     */
    public static function getOfxTransfers(): array {
        $db = Database::getConnection();
        $ignore = Tag::getIgnoreId();
        $sql = 'SELECT t.`id`, t.`date`, t.`amount`, t.`description`, '
             . 'a.`name` AS account_name '
             . 'FROM `transactions` t '
             . 'JOIN `accounts` a ON t.`account_id` = a.`id` '
             . "WHERE t.`ofx_type` = 'XFER' AND (t.`tag_id` IS NULL OR t.`tag_id` != :ignore) "
             . 'ORDER BY t.`date`';
        $stmt = $db->prepare($sql);
        $stmt->execute(['ignore' => $ignore]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Locate transactions that appear to be transfers but are not yet linked.
     * Matches items on the same date with opposite amounts where neither side
     * has a transfer_id.
     *
     * @return array<int, array{date:string, from_id:int, from_account:string, from_amount:float, from_description:string,
     *                          to_id:int, to_account:string, to_amount:float, to_description:string}>
     */
    public static function getTransferCandidates(): array {
        $db = Database::getConnection();
        $ignore = Tag::getIgnoreId();
        $sql = 'SELECT t1.id AS id1, t1.amount AS amt1, t1.description AS desc1, a1.name AS acc1, '
             . 't2.id AS id2, t2.amount AS amt2, t2.description AS desc2, a2.name AS acc2, '
             . 't1.date '
             . 'FROM `transactions` t1 '
             . 'JOIN `transactions` t2 ON t1.`date` = t2.`date` '
             . 'AND t1.`amount` = -t2.`amount` '
             . 'AND t1.`id` < t2.`id` '
             . 'AND t1.`account_id` != t2.`account_id` '
             . 'JOIN `accounts` a1 ON t1.`account_id` = a1.`id` '
             . 'JOIN `accounts` a2 ON t2.`account_id` = a2.`id` '
             . 'WHERE t1.`transfer_id` IS NULL '
             . 'AND t2.`transfer_id` IS NULL '
             . 'AND (t1.`tag_id` IS NULL OR t1.`tag_id` != :ignore) '
             . 'AND (t2.`tag_id` IS NULL OR t2.`tag_id` != :ignore)';
        $stmt = $db->prepare($sql);
        $stmt->execute(['ignore' => $ignore]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            if ((float)$row['amt1'] < 0) {
                $fromId = (int)$row['id1'];
                $fromAcc = $row['acc1'];
                $fromAmt = (float)$row['amt1'];
                $fromDesc = $row['desc1'];
                $toId = (int)$row['id2'];
                $toAcc = $row['acc2'];
                $toAmt = (float)$row['amt2'];
                $toDesc = $row['desc2'];
            } else {
                $fromId = (int)$row['id2'];
                $fromAcc = $row['acc2'];
                $fromAmt = (float)$row['amt2'];
                $fromDesc = $row['desc2'];
                $toId = (int)$row['id1'];
                $toAcc = $row['acc1'];
                $toAmt = (float)$row['amt1'];
                $toDesc = $row['desc1'];
            }
            $result[] = [
                'date' => $row['date'],
                'from_id' => $fromId,
                'from_account' => $fromAcc,
                'from_amount' => $fromAmt,
                'from_description' => $fromDesc,
                'to_id' => $toId,
                'to_account' => $toAcc,
                'to_amount' => $toAmt,
                'to_description' => $toDesc
            ];
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
     * Remove a transfer link using one of the transaction IDs.
     * Both sides of the pair are cleared so they appear in reports again.
     */
    public static function unlinkTransferById(int $id): bool {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT `transfer_id` FROM `transactions` WHERE `id` = :id');
        $stmt->execute(['id' => $id]);
        $tid = $stmt->fetchColumn();
        if ($tid === false || $tid === null) {
            return false;
        }
        $upd = $db->prepare('UPDATE `transactions` SET `transfer_id` = NULL WHERE `transfer_id` = :tid');
        return $upd->execute(['tid' => $tid]);
    }

    /**
     * Mark the given transactions as transfers without pairing.
     * Each transaction gets its own transfer_id so it is ignored in reports.
     *
     * @param int[] $ids
     * @return int Number of transactions updated.
     */
    public static function markTransfers(array $ids): int {
        $db = Database::getConnection();
        $upd = $db->prepare('UPDATE `transactions` SET `transfer_id` = `id` WHERE `id` = :id AND `transfer_id` IS NULL');
        $count = 0;
        foreach ($ids as $id) {
            if ($upd->execute(['id' => $id])) {
                $count += $upd->rowCount();
            }
        }
        return $count;
    }

    /**
     * Link any unpaired transactions that have matching dates and opposite amounts.
     * Useful when descriptions differ but the amounts cancel out.
     *
     * @return int Number of pairs linked.
     */
    public static function assistTransfers(): int {
        $db = Database::getConnection();
        $ignore = Tag::getIgnoreId();
        $sql = 'SELECT t1.id AS id1, t2.id AS id2 '
             . 'FROM `transactions` t1 '
             . 'JOIN `transactions` t2 ON t1.`date` = t2.`date` '
             . 'AND t1.`amount` = -t2.`amount` '
             . 'AND t1.`id` < t2.`id` '
             . 'AND t1.`account_id` != t2.`account_id` '
             . 'WHERE t1.`transfer_id` IS NULL '
             . 'AND t2.`transfer_id` IS NULL '
             . 'AND (t1.`tag_id` IS NULL OR t1.`tag_id` != :ignore) '
             . 'AND (t2.`tag_id` IS NULL OR t2.`tag_id` != :ignore)';
        $stmt = $db->prepare($sql);
        $stmt->execute(['ignore' => $ignore]);
        $pairs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count = 0;
        foreach ($pairs as $p) {
            if (self::linkTransfer((int)$p['id1'], (int)$p['id2'])) {
                $count++;
            }
        }
        return $count;
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

    /**
     * Return the total number of untagged transactions.
     */
    public static function getUntaggedTotal(): int {
        $db = Database::getConnection();
        $stmt = $db->query('SELECT COUNT(*) FROM `transactions` WHERE `tag_id` IS NULL');
        return (int)$stmt->fetchColumn();
    }

    /**
     * Analyse the last 12 months to find regularly occurring spend items.
     * Transactions marked as transfers are ignored.
     *
     * @return array{description:string, occurrences:int, total:float}[]
     */
    public static function getRecurringSpend(bool $income = false): array {
        $db = Database::getConnection();
        $ignore = Tag::getIgnoreId();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $dayExpr = $driver === 'sqlite' ? "CAST(STRFTIME('%d', `date`) AS INTEGER)" : 'DAY(`date`)';
        $dateCond = $driver === 'sqlite'
            ? "`date` >= DATE('now','-12 months')"
            : '`date` >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)';
        $recentCond = $driver === 'sqlite'
            ? "MAX(`date`) >= DATE('now','-40 days')"
            : 'MAX(`date`) >= DATE_SUB(CURDATE(), INTERVAL 40 DAY)';
        $sign = $income ? '>' : '<';
        $sql = "SELECT `description`, $dayExpr AS `day`, COUNT(*) AS occurrences, "
             . "SUM(`amount`) AS total, AVG(`amount`) AS average, MAX(`date`) AS last_date "
             . 'FROM `transactions` '
             . 'WHERE ' . $dateCond . ' '
             . 'AND `amount` ' . $sign . ' 0 '
             . 'AND `transfer_id` IS NULL '
             . 'AND (`tag_id` IS NULL OR `tag_id` != :ignore) '
             . "GROUP BY `description`, $dayExpr "
             . 'HAVING COUNT(*) > 1 AND ' . $recentCond . ' '
             . 'ORDER BY `description`, `day`';
        $stmt = $db->prepare($sql);
        $stmt->execute(['ignore' => $ignore]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['day'] = (int)$row['day'];
            $row['occurrences'] = (int)$row['occurrences'];
            $row['total'] = abs((float)$row['total']);
            $row['average'] = abs((float)$row['average']);
            // fetch the most recent amount for next-month estimates
            $stmtLast = $db->prepare('SELECT `amount` FROM `transactions` '
                . 'WHERE `description` = :desc AND ' . $dayExpr . ' = :day '
                . 'ORDER BY `date` DESC LIMIT 1');
            $stmtLast->execute(['desc' => $row['description'], 'day' => $row['day']]);
            $last = $stmtLast->fetchColumn();
            $row['last_amount'] = $last !== false ? abs((float)$last) : $row['average'];
            unset($row['last_date']);
        }
        return $rows;
    }
}
?>
