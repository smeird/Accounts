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
    const TRANSFER_MATCH_WINDOW_DAYS = 3;

    /**
     * Determine whether two values are equal and opposite at currency precision.
     */
    private static function amountsAreOpposite(float $amountA, float $amountB): bool {
        if (abs($amountA) < 0.00001 || abs($amountB) < 0.00001) {
            return false;
        }
        if (($amountA < 0 && $amountB <= 0) || ($amountA > 0 && $amountB >= 0)) {
            return false;
        }

        return abs(round($amountA + $amountB, 2)) < 0.00001;
    }

    /**
     * Banks often describe the two legs differently and can settle them on
     * different days. Treat explicit OFX types and conservative bank wording as
     * evidence that an opposite amount is an internal transfer.
     */
    private static function hasTransferSignal(array $row): bool {
        if (strtoupper(trim((string)($row['ofx_type'] ?? ''))) === 'XFER') {
            return true;
        }
        $text = strtolower(trim((string)($row['description'] ?? '') . ' ' . (string)($row['memo'] ?? '')));
        return preg_match('/\b(?:transfer|xfer|internal\s+(?:move|transfer)|own\s+account|to\s+savings|from\s+savings)\b/i', $text) === 1;
    }

    private static function daysApart(string $dateA, string $dateB): int {
        try {
            $a = new DateTimeImmutable($dateA);
            $b = new DateTimeImmutable($dateB);
            return (int)$a->diff($b)->format('%a');
        } catch (Exception $e) {
            return PHP_INT_MAX;
        }
    }

    /**
     * Return a lower-is-better confidence score, or null when two rows are not a
     * safe automatic transfer match.
     */
    private static function transferMatchScore(array $rowA, array $rowB): ?int {
        if ((int)$rowA['account_id'] === (int)$rowB['account_id']) {
            return null;
        }
        if (!self::amountsAreOpposite((float)$rowA['amount'], (float)$rowB['amount'])) {
            return null;
        }
        $days = self::daysApart((string)$rowA['date'], (string)$rowB['date']);
        if ($days > self::TRANSFER_MATCH_WINDOW_DAYS) {
            return null;
        }
        $signalA = self::hasTransferSignal($rowA);
        $signalB = self::hasTransferSignal($rowB);
        if ($days > 0 && !$signalA && !$signalB) {
            return null;
        }

        $score = $days * 100;
        if ($signalA) $score -= 15;
        if ($signalB) $score -= 15;
        if (strcasecmp(trim((string)$rowA['description']), trim((string)$rowB['description'])) === 0) {
            $score -= 10;
        }
        return $score;
    }

    /**
     * Find a unique best match for a newly imported transaction. An existing
     * explicit singleton marker (transfer_id = id) remains eligible for pairing.
     */
    private static function findAutomaticTransferMatch(array $transaction): ?int {
        $db = Database::getConnection();
        $start = date('Y-m-d', strtotime($transaction['date'] . ' -' . self::TRANSFER_MATCH_WINDOW_DAYS . ' days'));
        $end = date('Y-m-d', strtotime($transaction['date'] . ' +' . self::TRANSFER_MATCH_WINDOW_DAYS . ' days'));
        $stmt = $db->prepare(
            'SELECT `id`, `account_id`, `date`, `amount`, `description`, `memo`, `ofx_type`, `transfer_id` '
            . 'FROM `transactions` WHERE `id` != :id AND `account_id` != :account '
            . 'AND `date` BETWEEN :start AND :end '
            . 'AND ABS(`amount` + :amount_sum) < 0.005 AND `amount` * :amount_sign < 0 '
            . 'AND (`transfer_id` IS NULL OR `transfer_id` = `id`)'
        );
        $stmt->execute([
            'id' => (int)$transaction['id'],
            'account' => (int)$transaction['account_id'],
            'start' => $start,
            'end' => $end,
            'amount_sum' => (float)$transaction['amount'],
            'amount_sign' => (float)$transaction['amount'],
        ]);

        $matches = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
            if (!self::transferRowAvailableForPairing($db, $candidate)) {
                continue;
            }
            $score = self::transferMatchScore($transaction, $candidate);
            if ($score !== null) {
                $matches[] = ['id' => (int)$candidate['id'], 'score' => $score];
            }
        }
        usort($matches, function ($a, $b) {
            return $a['score'] === $b['score'] ? $a['id'] <=> $b['id'] : $a['score'] <=> $b['score'];
        });
        if (!$matches || (isset($matches[1]) && $matches[0]['score'] === $matches[1]['score'])) {
            return null;
        }
        return $matches[0]['id'];
    }

    /**
     * Insert a new transaction and attempt to auto-tag and link transfers.
     */
    public static function create(int $account, string $date, float $amount, string $description, ?string $memo = null, ?int $category = null, ?int $tag = null, ?int $group = null, ?string $ofx_id = null, ?string $ofx_type = null, ?string $bank_ofx_id = null): int {
        $db = Database::getConnection();

        $substr = function_exists('mb_substr') ? 'mb_substr' : 'substr';
        $description = $substr($description, 0, self::DESC_MAX_LENGTH);
        $memo = $memo === null ? null : $substr($memo, 0, self::MEMO_MAX_LENGTH);
        $ofx_id = $ofx_id === null ? null : $substr($ofx_id, 0, self::ID_MAX_LENGTH);
        $ofx_type = $ofx_type === null ? null : $substr($ofx_type, 0, self::TYPE_MAX_LENGTH);
        $bank_ofx_id = $bank_ofx_id === null ? null : $substr($bank_ofx_id, 0, self::ID_MAX_LENGTH);
        $ofx_id = $ofx_id === null || trim($ofx_id) === '' ? null : trim($ofx_id);
        $bank_ofx_id = $bank_ofx_id === null || trim($bank_ofx_id) === '' ? null : trim($bank_ofx_id);

        // A bank-provided FITID is authoritative within its account. Core field
        // comparisons must not discard a different FITID: two genuine purchases
        // can have the same merchant, amount, and date.
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

        if ($ofx_id !== null) {
            $check = $db->prepare('SELECT `id`, `bank_ofx_id` FROM `transactions` WHERE `ofx_id` = :oid LIMIT 1');
            $check->execute(['oid' => $ofx_id]);
            if ($row = $check->fetch(PDO::FETCH_ASSOC)) {
                $storedBankId = $row['bank_ofx_id'] === null ? null : trim((string)$row['bank_ofx_id']);
                if ($bank_ofx_id !== null && $storedBankId !== null && $storedBankId !== $bank_ofx_id) {
                    throw new RuntimeException('OFX identity collision detected');
                }
                return 0;
            }
        }

        // Only use a core-field fallback when the caller has no stable identity.
        // Include the memo and exact date; fuzzy multi-day matching can silently
        // remove legitimate repeated purchases.
        if ($bank_ofx_id === null && $ofx_id === null) {
            $coreCheck = $db->prepare(
                'SELECT `id` FROM `transactions` '
                . 'WHERE `account_id` = :account AND `date` = :date AND `amount` = :amount '
                . 'AND UPPER(TRIM(`description`)) = UPPER(TRIM(:description)) '
                . 'AND UPPER(TRIM(COALESCE(`memo`, \'\'))) = UPPER(TRIM(:memo)) '
                . 'LIMIT 1'
            );
            $coreCheck->execute([
                'account' => $account,
                'date' => $date,
                'amount' => $amount,
                'description' => $description,
                'memo' => $memo ?? '',
            ]);
            if ($coreCheck->fetch(PDO::FETCH_ASSOC)) {
                return 0;
            }
        }

        if ($tag === null) {
            $tag = Tag::findMatch(Tag::buildMatchText($description, $memo), $amount);
            if ($tag === null && $ofx_type === 'INT') {
                $tag = Tag::getInterestChargeId();
            }
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

        // Attempt to detect the opposite leg in another owned account. Matching
        // spans the normal settlement window while avoiding ambiguous equal-value
        // pairs and different-day matches with no transfer evidence.
        $inserted = [
            'id' => $id, 'account_id' => $account, 'date' => $date, 'amount' => $amount,
            'description' => $description, 'memo' => $memo, 'ofx_type' => $ofx_type,
            'transfer_id' => null,
        ];
        $matchId = self::findAutomaticTransferMatch($inserted);
        if ($matchId !== null) {
            self::linkTransfer($id, $matchId);
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
        if ($month < 1 || $month > 12 || $year < 1) {
            throw new InvalidArgumentException('A valid statement month and year are required.');
        }
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = (new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');
        $sql = 'SELECT t.`id`, t.`account_id`, t.`date`, t.`amount`, t.`description`, t.`memo`, '
             . 't.`category_id`, t.`tag_id`, t.`group_id`, t.`transfer_id`, '
             . 'c.`name` AS category_name, s.`name` AS segment_name, tg.`name` AS tag_name, g.`name` AS group_name '
             . 'FROM `transactions` t '
             . 'LEFT JOIN `categories` c ON t.`category_id` = c.`id` '
             . 'LEFT JOIN `segments` s ON t.`segment_id` = s.`id` '
             . 'LEFT JOIN `tags` tg ON t.`tag_id` = tg.`id` '
             . 'LEFT JOIN `transaction_groups` g ON t.`group_id` = g.`id` '
             . 'WHERE t.`date` >= :start AND t.`date` < :end '
             . 'AND (t.`tag_id` IS NULL OR t.`tag_id` != :ignore)';
        if ($onlyUntagged) {
            $sql .= ' AND t.`tag_id` IS NULL AND t.`transfer_id` IS NULL';
        }
        $sql .= ' ORDER BY t.`date`';
        $stmt = $db->prepare($sql);
        $stmt->execute(['start' => $start, 'end' => $end, 'ignore' => $ignore]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve all transactions for a specific account ordered by date.
     */
    public static function getByAccount(int $accountId): array {
        $db = Database::getConnection();
        $ignore = Tag::getIgnoreId();
        $sql = 'SELECT t.`id`, t.`date`, t.`amount`, t.`description`, t.`memo`, t.`transfer_id`, '
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
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $yearExpression = $driver === 'sqlite' ? 'CAST(SUBSTR(`date`, 1, 4) AS INTEGER)' : 'YEAR(`date`)';
        $monthExpression = $driver === 'sqlite' ? 'CAST(SUBSTR(`date`, 6, 2) AS INTEGER)' : 'MONTH(`date`)';
        $stmt = $db->prepare("SELECT DISTINCT $yearExpression AS year, $monthExpression AS month FROM `transactions` WHERE `tag_id` IS NULL OR `tag_id` != :ignore ORDER BY year DESC, month DESC");
        $stmt->execute(['ignore' => $ignore]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * List years that have at least one transaction recorded.
     */
    public static function getAvailableYears(): array {
        $db = Database::getConnection();
        $ignore = Tag::getIgnoreId();
        $yearExpression = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'CAST(SUBSTR(`date`, 1, 4) AS INTEGER)'
            : 'YEAR(`date`)';
        $stmt = $db->prepare("SELECT DISTINCT $yearExpression AS year FROM `transactions` WHERE `tag_id` IS NULL OR `tag_id` != :ignore ORDER BY year");
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
    public static function search(
        ?string $value,
        ?float $minAmount = null,
        ?float $maxAmount = null,
        ?string $start = null,
        ?string $end = null,
        ?string $dimension = null,
        ?int $dimensionId = null,
        bool $unclassified = false,
        bool $spendingOnly = false
    ): array {
        $db = Database::getConnection();

        $sql = 'SELECT t.`id`, t.`account_id`, t.`date`, t.`amount`, t.`description`, t.`memo`, t.`transfer_id`, '
             . 'c.`name` AS category_name, s.`name` AS segment_name, tg.`name` AS tag_name, g.`name` AS group_name '
             . 'FROM `transactions` t '
             . 'LEFT JOIN `categories` c ON t.`category_id` = c.`id` '
             . 'LEFT JOIN `segments` s ON c.`segment_id` = s.`id` '
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

        if ($start !== null && $start !== '') {
            $conditions[] = 't.`date` >= :start';
            $params['start'] = $start;
        }
        if ($end !== null && $end !== '') {
            $conditions[] = 't.`date` <= :end';
            $params['end'] = $end;
        }

        if ($dimension !== null) {
            $dimensionColumns = [
                'category' => 't.`category_id`',
                'segment' => 'c.`segment_id`',
                'group' => 't.`group_id`',
                'tag' => 't.`tag_id`',
            ];
            if (!isset($dimensionColumns[$dimension])) {
                throw new InvalidArgumentException('Unsupported search dimension');
            }
            if ($unclassified) {
                $conditions[] = $dimensionColumns[$dimension] . ' IS NULL';
            } elseif ($dimensionId !== null) {
                $conditions[] = $dimensionColumns[$dimension] . ' = :dimension_id';
                $params['dimension_id'] = $dimensionId;
            }
        }
        if ($spendingOnly) {
            $conditions[] = 't.`amount` < 0';
            $conditions[] = 't.`transfer_id` IS NULL';
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
     * Matches opposite amounts within the normal bank settlement window. Same-
     * day matches retain the existing exact-amount behaviour; different-day
     * matches require an explicit transfer signal. Ambiguous matches are omitted.
     *
     * @return array<int, array{date:string, from_id:int, from_account:string, from_amount:float, from_description:string,
     *                          to_id:int, to_account:string, to_amount:float, to_description:string}>
     */
    public static function getTransferCandidates(): array {
        $db = Database::getConnection();
        $ignore = Tag::getIgnoreId();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $dateDistance = $driver === 'sqlite'
            ? 'ABS(julianday(t1.`date`) - julianday(t2.`date`))'
            : 'ABS(DATEDIFF(t1.`date`, t2.`date`))';
        $sql = 'SELECT t1.id AS id1, t1.amount AS amt1, t1.description AS desc1, a1.name AS acc1, '
             . 't1.memo AS memo1, t1.ofx_type AS type1, t1.transfer_id AS transfer1, t1.account_id AS account1, t1.date AS date1, '
             . 't2.id AS id2, t2.amount AS amt2, t2.description AS desc2, a2.name AS acc2, '
             . 't2.memo AS memo2, t2.ofx_type AS type2, t2.transfer_id AS transfer2, t2.account_id AS account2, t2.date AS date2 '
             . 'FROM `transactions` t1 '
             . 'JOIN `transactions` t2 ON ABS(t1.`amount` + t2.`amount`) < 0.005 '
             . 'AND t1.`amount` * t2.`amount` < 0 '
             . 'AND t1.`id` < t2.`id` '
             . 'AND t1.`account_id` != t2.`account_id` '
             . 'AND ' . $dateDistance . ' <= ' . self::TRANSFER_MATCH_WINDOW_DAYS . ' '
             . 'JOIN `accounts` a1 ON t1.`account_id` = a1.`id` '
             . 'JOIN `accounts` a2 ON t2.`account_id` = a2.`id` '
             . 'WHERE (t1.`transfer_id` IS NULL OR t1.`transfer_id` = t1.`id`) '
             . 'AND (t2.`transfer_id` IS NULL OR t2.`transfer_id` = t2.`id`) '
             . 'AND (t1.`tag_id` IS NULL OR t1.`tag_id` != :ignore) '
             . 'AND (t2.`tag_id` IS NULL OR t2.`tag_id` != :ignore)';
        $stmt = $db->prepare($sql);
        $stmt->execute(['ignore' => $ignore]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $possible = [];
        foreach ($rows as $row) {
            $first = [
                'id' => (int)$row['id1'], 'account_id' => (int)$row['account1'], 'date' => $row['date1'],
                'amount' => (float)$row['amt1'], 'description' => $row['desc1'], 'memo' => $row['memo1'],
                'ofx_type' => $row['type1'], 'transfer_id' => $row['transfer1'],
            ];
            $second = [
                'id' => (int)$row['id2'], 'account_id' => (int)$row['account2'], 'date' => $row['date2'],
                'amount' => (float)$row['amt2'], 'description' => $row['desc2'], 'memo' => $row['memo2'],
                'ofx_type' => $row['type2'], 'transfer_id' => $row['transfer2'],
            ];
            if (!self::transferRowAvailableForPairing($db, $first) || !self::transferRowAvailableForPairing($db, $second)) {
                continue;
            }
            $score = self::transferMatchScore($first, $second);
            if ($score === null) {
                continue;
            }
            if ((float)$row['amt1'] < 0) {
                $fromId = (int)$row['id1'];
                $fromAcc = $row['acc1'];
                $fromAmt = (float)$row['amt1'];
                $fromDesc = $row['desc1'];
                $fromDate = $row['date1'];
                $toId = (int)$row['id2'];
                $toAcc = $row['acc2'];
                $toAmt = (float)$row['amt2'];
                $toDesc = $row['desc2'];
                $toDate = $row['date2'];
            } else {
                $fromId = (int)$row['id2'];
                $fromAcc = $row['acc2'];
                $fromAmt = (float)$row['amt2'];
                $fromDesc = $row['desc2'];
                $fromDate = $row['date2'];
                $toId = (int)$row['id1'];
                $toAcc = $row['acc1'];
                $toAmt = (float)$row['amt1'];
                $toDesc = $row['desc1'];
                $toDate = $row['date1'];
            }
            $possible[] = [
                'date' => $fromDate,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'from_id' => $fromId,
                'from_account' => $fromAcc,
                'from_amount' => $fromAmt,
                'from_description' => $fromDesc,
                'to_id' => $toId,
                'to_account' => $toAcc,
                'to_amount' => $toAmt,
                'to_description' => $toDesc,
                '_score' => $score,
            ];
        }

        // Only surface reciprocal, unique best matches. This prevents "Mark all"
        // from arbitrarily pairing several identical-value transactions.
        $best = [];
        foreach ($possible as $pair) {
            foreach ([$pair['from_id'], $pair['to_id']] as $id) {
                if (!isset($best[$id]) || $pair['_score'] < $best[$id]['score']) {
                    $best[$id] = ['score' => $pair['_score'], 'count' => 1];
                } elseif ($pair['_score'] === $best[$id]['score']) {
                    $best[$id]['count']++;
                }
            }
        }
        $result = [];
        foreach ($possible as $pair) {
            $fromBest = $best[$pair['from_id']];
            $toBest = $best[$pair['to_id']];
            if ($pair['_score'] !== $fromBest['score'] || $fromBest['count'] !== 1
                || $pair['_score'] !== $toBest['score'] || $toBest['count'] !== 1) {
                continue;
            }
            unset($pair['_score']);
            $result[] = $pair;
        }
        usort($result, function ($a, $b) {
            return strcmp($a['date'], $b['date']) ?: ($a['from_id'] <=> $b['from_id']);
        });
        return $result;
    }

    /**
     * Link two existing transactions as a transfer pair.
     */
    public static function linkTransfer(int $id1, int $id2): bool {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT `id`, `account_id`, `amount`, `transfer_id` FROM `transactions` WHERE `id` IN (?, ?)');
        $stmt->execute([$id1, $id2]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 2) {
            return false;
        }

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int)$row['id']] = $row;
        }
        if (!isset($byId[$id1]) || !isset($byId[$id2])) {
            return false;
        }

        $row1 = $byId[$id1];
        $row2 = $byId[$id2];
        if ((int)$row1['account_id'] === (int)$row2['account_id']) {
            return false;
        }
        if (!self::amountsAreOpposite((float)$row1['amount'], (float)$row2['amount'])) {
            return false;
        }

        $tid = min($id1, $id2);
        $transfer1 = $row1['transfer_id'] === null ? null : (int)$row1['transfer_id'];
        $transfer2 = $row2['transfer_id'] === null ? null : (int)$row2['transfer_id'];
        if ($transfer1 === $tid && $transfer2 === $tid) {
            return true;
        }
        if (!self::transferRowAvailableForPairing($db, $row1) || !self::transferRowAvailableForPairing($db, $row2)) {
            return false;
        }

        $upd = $db->prepare('UPDATE `transactions` SET `transfer_id` = :tid WHERE `id` IN (:a, :b)');
        return $upd->execute(['tid' => $tid, 'a' => $id1, 'b' => $id2]);
    }

    /**
     * A self marker represents an explicitly marked singleton and can be upgraded
     * to a pair. A marker already shared by two rows must remain immutable.
     */
    private static function transferRowAvailableForPairing(PDO $db, array $row): bool {
        if ($row['transfer_id'] === null) {
            return true;
        }
        $current = (int)$row['transfer_id'];
        if ($current !== (int)$row['id']) {
            return false;
        }
        $stmt = $db->prepare('SELECT COUNT(*) FROM `transactions` WHERE `transfer_id` = :tid');
        $stmt->execute(['tid' => $current]);
        return (int)$stmt->fetchColumn() === 1;
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
     * Link unambiguous, unpaired transactions within the settlement window when
     * their amounts cancel out and their transfer signals are compatible.
     *
     * @return int Number of pairs linked.
     */
    public static function assistTransfers(): int {
        $count = 0;
        foreach (self::getTransferCandidates() as $pair) {
            if (self::linkTransfer((int)$pair['from_id'], (int)$pair['to_id'])) {
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
             . 'FROM `transactions` WHERE `tag_id` IS NULL AND `transfer_id` IS NULL '
             . 'GROUP BY `description`, `memo` ORDER BY `count` DESC';
        $stmt = $db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return the total number of untagged transactions.
     */
    public static function getUntaggedTotal(): int {
        $db = Database::getConnection();
        $stmt = $db->query('SELECT COUNT(*) FROM `transactions` WHERE `tag_id` IS NULL AND `transfer_id` IS NULL');
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
                . 'AND `amount` ' . $sign . ' 0 AND `transfer_id` IS NULL '
                . 'AND (`tag_id` IS NULL OR `tag_id` != :ignore) '
                . 'ORDER BY `date` DESC LIMIT 1');
            $stmtLast->execute(['desc' => $row['description'], 'day' => $row['day'], 'ignore' => $ignore]);
            $last = $stmtLast->fetchColumn();
            $row['last_amount'] = $last !== false ? abs((float)$last) : $row['average'];
            unset($row['last_date']);

 
        }
        return $rows;
    }
}
?>
