<?php
// Builds the comparison-aware dataset used by the Financial Trends dashboard.
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/Tag.php';

class FinancialTrends {
    private const DIMENSIONS = ['category', 'segment', 'group', 'tag'];

    private static function validateDate(string $date): DateTimeImmutable {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Dates must use YYYY-MM-DD format');
        }
        return $parsed;
    }

    private static function dimensionLabel(string $dimension): string {
        return [
            'category' => 'Uncategorised',
            'segment' => 'Unsegmented',
            'group' => 'Ungrouped',
            'tag' => 'Untagged',
        ][$dimension];
    }

    /** @return array<int,array<string,mixed>> */
    private static function rowsForRange(string $start, string $end): array {
        $db = Database::getConnection();
        $ignore = Tag::getIgnoreId();
        $stmt = $db->prepare(
            'SELECT t.`id`, t.`date`, t.`amount`, '
            . 'c.`id` AS category_id, c.`name` AS category_name, '
            . 's.`id` AS segment_id, s.`name` AS segment_name, '
            . 'g.`id` AS group_id, g.`name` AS group_name, '
            . 'tg.`id` AS tag_id, tg.`name` AS tag_name '
            . 'FROM `transactions` t '
            . 'LEFT JOIN `categories` c ON c.`id` = t.`category_id` '
            . 'LEFT JOIN `segments` s ON s.`id` = c.`segment_id` '
            . 'LEFT JOIN `transaction_groups` g ON g.`id` = t.`group_id` '
            . 'LEFT JOIN `tags` tg ON tg.`id` = t.`tag_id` '
            . 'WHERE t.`date` >= :start AND t.`date` <= :end '
            . 'AND t.`transfer_id` IS NULL '
            . 'AND (t.`tag_id` IS NULL OR t.`tag_id` != :ignore) '
            . 'ORDER BY t.`date`, t.`id`'
        );
        $stmt->execute(['start' => $start, 'end' => $end, 'ignore' => $ignore]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function chooseGrain(DateTimeImmutable $start, DateTimeImmutable $end): string {
        $days = (int)$start->diff($end)->format('%a') + 1;
        if ($days <= 62) {
            return 'day';
        }
        if ($days <= 900) {
            return 'month';
        }
        return 'year';
    }

    /** @return array<string,array{key:string,label:string,income:float,spending:float,cashflow:float}> */
    private static function emptyBuckets(DateTimeImmutable $start, DateTimeImmutable $end, string $grain): array {
        $buckets = [];
        if ($grain === 'day') {
            for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 day')) {
                $key = $cursor->format('Y-m-d');
                $buckets[$key] = ['key' => $key, 'label' => $cursor->format('j M'), 'income' => 0.0, 'spending' => 0.0, 'cashflow' => 0.0];
            }
            return $buckets;
        }

        if ($grain === 'month') {
            $cursor = $start->modify('first day of this month');
            $last = $end->modify('first day of this month');
            for (; $cursor <= $last; $cursor = $cursor->modify('+1 month')) {
                $key = $cursor->format('Y-m');
                $buckets[$key] = ['key' => $key, 'label' => $cursor->format('M y'), 'income' => 0.0, 'spending' => 0.0, 'cashflow' => 0.0];
            }
            return $buckets;
        }

        for ($year = (int)$start->format('Y'); $year <= (int)$end->format('Y'); $year++) {
            $key = (string)$year;
            $buckets[$key] = ['key' => $key, 'label' => $key, 'income' => 0.0, 'spending' => 0.0, 'cashflow' => 0.0];
        }
        return $buckets;
    }

    private static function bucketKey(string $date, string $grain): string {
        if ($grain === 'day') {
            return $date;
        }
        if ($grain === 'month') {
            return substr($date, 0, 7);
        }
        return substr($date, 0, 4);
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function aggregate(
        array $rows,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        string $grain,
        string $dimension
    ): array {
        $series = self::emptyBuckets($start, $end, $grain);
        $income = 0.0;
        $spending = 0.0;
        $breakdown = [];
        $spendingTransactions = 0;
        $classification = array_fill_keys(self::DIMENSIONS, 0.0);

        foreach ($rows as $row) {
            $amount = (float)$row['amount'];
            $key = self::bucketKey((string)$row['date'], $grain);
            if (!isset($series[$key])) {
                continue;
            }

            if ($amount > 0) {
                $income += $amount;
                $series[$key]['income'] += $amount;
            } elseif ($amount < 0) {
                $expense = abs($amount);
                $spending += $expense;
                $spendingTransactions++;
                $series[$key]['spending'] += $expense;

                foreach (self::DIMENSIONS as $coverageDimension) {
                    if ($row[$coverageDimension . '_id'] !== null) {
                        $classification[$coverageDimension] += $expense;
                    }
                }

                $id = $row[$dimension . '_id'] !== null ? (int)$row[$dimension . '_id'] : null;
                $name = trim((string)($row[$dimension . '_name'] ?? '')) ?: self::dimensionLabel($dimension);
                $breakdownKey = $id === null ? 'unclassified' : 'id:' . $id;
                if (!isset($breakdown[$breakdownKey])) {
                    $breakdown[$breakdownKey] = ['id' => $id, 'name' => $name, 'amount' => 0.0, 'transactions' => 0];
                }
                $breakdown[$breakdownKey]['amount'] += $expense;
                $breakdown[$breakdownKey]['transactions']++;
            }
        }

        foreach ($series as &$bucket) {
            $bucket['income'] = round($bucket['income'], 2);
            $bucket['spending'] = round($bucket['spending'], 2);
            $bucket['cashflow'] = round($bucket['income'] - $bucket['spending'], 2);
        }
        unset($bucket);

        foreach ($breakdown as &$item) {
            $item['amount'] = round($item['amount'], 2);
        }
        unset($item);

        $coverage = [];
        foreach (self::DIMENSIONS as $coverageDimension) {
            $coverage[$coverageDimension] = [
                'amount' => round($classification[$coverageDimension], 2),
                'percentage' => $spending > 0 ? round($classification[$coverageDimension] / $spending * 100, 1) : 100.0,
            ];
        }

        return [
            'metrics' => [
                'income' => round($income, 2),
                'spending' => round($spending, 2),
                'cashflow' => round($income - $spending, 2),
                'savings_rate' => $income > 0 ? round(($income - $spending) / $income * 100, 1) : 0.0,
                'transaction_count' => count($rows),
                'spending_transactions' => $spendingTransactions,
            ],
            'series' => array_values($series),
            'breakdown' => $breakdown,
            'coverage' => $coverage,
        ];
    }

    private static function change(float $current, float $previous): array {
        return [
            'amount' => round($current - $previous, 2),
            'percentage' => abs($previous) >= 0.005
                ? round(($current - $previous) / abs($previous) * 100, 1)
                : null,
        ];
    }

    /** @param array<string,array<string,mixed>> $current @param array<string,array<string,mixed>> $previous */
    private static function mergeBreakdowns(array $current, array $previous, float $totalSpending): array {
        $keys = array_values(array_unique(array_merge(array_keys($current), array_keys($previous))));
        $rows = [];
        foreach ($keys as $key) {
            $currentItem = $current[$key] ?? null;
            $previousItem = $previous[$key] ?? null;
            $amount = (float)($currentItem['amount'] ?? 0);
            $comparisonAmount = (float)($previousItem['amount'] ?? 0);
            $rows[] = [
                'id' => $currentItem['id'] ?? $previousItem['id'] ?? null,
                'name' => (string)($currentItem['name'] ?? $previousItem['name'] ?? 'Unknown'),
                'amount' => round($amount, 2),
                'comparison_amount' => round($comparisonAmount, 2),
                'change' => round($amount - $comparisonAmount, 2),
                'change_percentage' => abs($comparisonAmount) >= 0.005
                    ? round(($amount - $comparisonAmount) / $comparisonAmount * 100, 1)
                    : null,
                'share' => $totalSpending > 0 ? round($amount / $totalSpending * 100, 1) : 0.0,
                'transactions' => (int)($currentItem['transactions'] ?? 0),
                'unclassified' => ($currentItem['id'] ?? $previousItem['id'] ?? null) === null,
            ];
        }
        usort($rows, function(array $left, array $right): int {
            $amountOrder = $right['amount'] <=> $left['amount'];
            if ($amountOrder !== 0) {
                return $amountOrder;
            }
            return abs($right['change']) <=> abs($left['change']);
        });
        return $rows;
    }

    public static function getSnapshot(
        string $start,
        string $end,
        string $dimension = 'category',
        ?string $comparisonStart = null,
        ?string $comparisonEnd = null
    ): array {
        $startDate = self::validateDate($start);
        $endDate = self::validateDate($end);
        if ($startDate > $endDate) {
            throw new InvalidArgumentException('The start date must be before the end date');
        }
        if (!in_array($dimension, self::DIMENSIONS, true)) {
            throw new InvalidArgumentException('Unsupported breakdown dimension');
        }

        if (($comparisonStart === null) !== ($comparisonEnd === null)) {
            throw new InvalidArgumentException('Both comparison dates are required');
        }

        $grain = self::chooseGrain($startDate, $endDate);
        $current = self::aggregate(self::rowsForRange($start, $end), $startDate, $endDate, $grain, $dimension);
        $previous = [
            'metrics' => ['income' => 0.0, 'spending' => 0.0, 'cashflow' => 0.0, 'savings_rate' => 0.0, 'transaction_count' => 0, 'spending_transactions' => 0],
            'series' => [],
            'breakdown' => [],
            'coverage' => [],
        ];
        $comparison = null;

        if ($comparisonStart !== null && $comparisonEnd !== null) {
            $comparisonStartDate = self::validateDate($comparisonStart);
            $comparisonEndDate = self::validateDate($comparisonEnd);
            if ($comparisonStartDate > $comparisonEndDate) {
                throw new InvalidArgumentException('The comparison start date must be before its end date');
            }
            $previous = self::aggregate(
                self::rowsForRange($comparisonStart, $comparisonEnd),
                $comparisonStartDate,
                $comparisonEndDate,
                self::chooseGrain($comparisonStartDate, $comparisonEndDate),
                $dimension
            );
            $comparison = [
                'start' => $comparisonStart,
                'end' => $comparisonEnd,
                'metrics' => $previous['metrics'],
                'changes' => [
                    'income' => self::change($current['metrics']['income'], $previous['metrics']['income']),
                    'spending' => self::change($current['metrics']['spending'], $previous['metrics']['spending']),
                    'cashflow' => self::change($current['metrics']['cashflow'], $previous['metrics']['cashflow']),
                    'savings_rate' => self::change($current['metrics']['savings_rate'], $previous['metrics']['savings_rate']),
                ],
            ];
        }

        return [
            'period' => ['start' => $start, 'end' => $end, 'grain' => $grain],
            'dimension' => $dimension,
            'metrics' => $current['metrics'],
            'comparison' => $comparison,
            'series' => $current['series'],
            'breakdown' => self::mergeBreakdowns(
                $current['breakdown'],
                $previous['breakdown'],
                (float)$current['metrics']['spending']
            ),
            'coverage' => $current['coverage'],
        ];
    }
}
?>
