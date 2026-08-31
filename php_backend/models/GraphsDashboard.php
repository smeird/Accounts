<?php
// Builds the chart-ready financial position snapshot used by Graphs.
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/YearlyDashboard.php';
require_once __DIR__ . '/Tag.php';

class GraphsDashboard {
    const CATEGORY_LIMIT = 7;
    const SEGMENT_LIMIT = 5;
    const TAG_LIMIT = 7;

    public static function getSnapshot(int $year): array {
        if ($year < 1900 || $year > 2200) {
            throw new InvalidArgumentException('Invalid year');
        }

        $annual = YearlyDashboard::getSnapshot($year);
        $activity = self::activityForYear($year);
        $position = self::accountPosition();
        $expenseBreakdowns = self::expenseBreakdowns($activity, (float)$annual['metrics']['spending']);
        $activeMonths = array_values(array_filter($annual['months'], function ($month) {
            return (float)$month['income'] > 0 || (float)$month['spending'] > 0;
        }));
        $latestMonth = !empty($activeMonths) ? end($activeMonths) : null;

        return [
            'year' => $year,
            'generated_at' => date(DATE_ATOM),
            'scope' => [
                'label' => !empty($latestMonth)
                    ? 'January to ' . $latestMonth['label'] . ' ' . $year
                    : 'No recorded activity in ' . $year,
                'latest_month' => $latestMonth['month'] ?? null,
                'transaction_count' => count($activity),
                'note' => 'Confirmed account transfers and IGNORE-tagged transactions are excluded.',
            ],
            'metrics' => [
                'balance' => $position['total'],
                'balance_as_of' => $position['as_of'],
                'income' => (float)$annual['metrics']['income'],
                'spending' => (float)$annual['metrics']['spending'],
                'cashflow' => (float)$annual['metrics']['cashflow'],
                'savings_rate' => (float)$annual['metrics']['savings_rate'],
                'average_monthly_spending' => count($activeMonths) > 0
                    ? round((float)$annual['metrics']['spending'] / count($activeMonths), 2)
                    : 0.0,
                'active_months' => (int)$annual['metrics']['active_months'],
                'negative_months' => (int)$annual['metrics']['negative_months'],
            ],
            'comparison' => $annual['comparison'],
            'months' => self::addCumulativeCashflow($annual['months']),
            'categories' => $expenseBreakdowns['categories'],
            'segments' => $expenseBreakdowns['segments'],
            'tags' => $expenseBreakdowns['tags'],
            'accounts' => $position['accounts'],
            'insights' => $annual['insights'],
        ];
    }

    private static function activityForYear(int $year): array {
        $db = Database::getConnection();
        $ignore = Tag::getIgnoreId();
        $stmt = $db->prepare(
            'SELECT t.`date`, t.`amount`, c.`id` AS category_id, '
            . 'COALESCE(c.`name`, \'Uncategorised\') AS category, '
            . 'cs.`id` AS segment_id, COALESCE(cs.`name`, \'Not segmented\') AS segment, '
            . 'tg.`id` AS tag_id, COALESCE(tg.`name`, \'Untagged\') AS tag '
            . 'FROM `transactions` t '
            . 'LEFT JOIN `categories` c ON c.`id` = t.`category_id` '
            . 'LEFT JOIN `segments` cs ON cs.`id` = c.`segment_id` '
            . 'LEFT JOIN `tags` tg ON tg.`id` = t.`tag_id` '
            . 'WHERE t.`date` >= :start AND t.`date` < :end '
            . 'AND t.`transfer_id` IS NULL '
            . 'AND (t.`tag_id` IS NULL OR t.`tag_id` != :ignore) '
            . 'ORDER BY t.`date` ASC, t.`id` ASC'
        );
        $stmt->execute([
            'start' => sprintf('%04d-01-01', $year),
            'end' => sprintf('%04d-01-01', $year + 1),
            'ignore' => $ignore,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function accountPosition(): array {
        $db = Database::getConnection();
        $rows = $db->query(
            'SELECT `id`, `name`, COALESCE(`ledger_balance`, 0) AS balance, `ledger_balance_date` '
            . 'FROM `accounts` WHERE `closed` = 0 ORDER BY `name` ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        $total = 0.0;
        $asOf = null;
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['balance'] = round((float)$row['balance'], 2);
            $total += $row['balance'];
            if (!empty($row['ledger_balance_date']) && ($asOf === null || $row['ledger_balance_date'] > $asOf)) {
                $asOf = $row['ledger_balance_date'];
            }
        }
        unset($row);
        usort($rows, function ($a, $b) {
            return abs($b['balance']) <=> abs($a['balance']);
        });
        return ['total' => round($total, 2), 'as_of' => $asOf, 'accounts' => $rows];
    }

    private static function addCumulativeCashflow(array $months): array {
        $cumulative = 0.0;
        foreach ($months as &$month) {
            $cumulative += (float)$month['cashflow'];
            $month['cumulative_cashflow'] = round($cumulative, 2);
        }
        unset($month);
        return $months;
    }

    private static function expenseBreakdowns(array $activity, float $totalSpending): array {
        $categoryTotals = [];
        $categoryMonths = [];
        $segmentTotals = [];
        $tagTotals = [];
        $categoryLabels = [];
        $segmentLabels = [];
        $tagLabels = [];

        foreach ($activity as $row) {
            $amount = (float)$row['amount'];
            if ($amount >= 0) {
                continue;
            }
            $expense = -$amount;
            $month = (int)substr((string)$row['date'], 5, 2);
            $categoryKey = $row['category_id'] === null ? 'unclassified' : 'id:' . (int)$row['category_id'];
            $segmentKey = $row['segment_id'] === null ? 'unclassified' : 'id:' . (int)$row['segment_id'];
            $tagKey = $row['tag_id'] === null ? 'unclassified' : 'id:' . (int)$row['tag_id'];
            $categoryLabels[$categoryKey] = (string)$row['category'];
            $segmentLabels[$segmentKey] = (string)$row['segment'];
            $tagLabels[$tagKey] = (string)$row['tag'];
            $categoryTotals[$categoryKey] = ($categoryTotals[$categoryKey] ?? 0.0) + $expense;
            if (!isset($categoryMonths[$categoryKey])) {
                $categoryMonths[$categoryKey] = array_fill(1, 12, 0.0);
            }
            if ($month >= 1 && $month <= 12) {
                $categoryMonths[$categoryKey][$month] += $expense;
            }
            $segmentTotals[$segmentKey] = ($segmentTotals[$segmentKey] ?? 0.0) + $expense;
            $tagTotals[$tagKey] = ($tagTotals[$tagKey] ?? 0.0) + $expense;
        }

        arsort($categoryTotals);
        arsort($segmentTotals);
        arsort($tagTotals);

        return [
            'categories' => self::rankedWithMonths(
                $categoryTotals,
                $categoryMonths,
                $categoryLabels,
                self::CATEGORY_LIMIT,
                $totalSpending,
                'Other spending'
            ),
            'segments' => self::ranked($segmentTotals, $segmentLabels, self::SEGMENT_LIMIT, $totalSpending, 'Other segments'),
            'tags' => self::ranked($tagTotals, $tagLabels, self::TAG_LIMIT, $totalSpending, 'Other tags'),
        ];
    }

    private static function ranked(array $totals, array $labels, int $limit, float $grandTotal, string $otherLabel): array {
        $head = array_slice($totals, 0, $limit, true);
        $tail = array_slice($totals, $limit, null, true);
        if (!empty($tail)) {
            $head[$otherLabel] = array_sum($tail);
        }
        $output = [];
        foreach ($head as $key => $amount) {
            $isOther = !empty($tail) && $key === $otherLabel;
            $output[] = [
                'id' => (!$isOther && strpos((string)$key, 'id:') === 0) ? (int)substr((string)$key, 3) : null,
                'name' => $isOther ? $otherLabel : (string)($labels[$key] ?? $key),
                'amount' => round((float)$amount, 2),
                'share' => $grandTotal > 0 ? round(((float)$amount / $grandTotal) * 100, 1) : 0.0,
                'is_other' => $isOther,
                'unclassified' => !$isOther && $key === 'unclassified',
                'member_ids' => $isOther ? array_values(array_map(function ($memberKey) {
                    return strpos((string)$memberKey, 'id:') === 0 ? (int)substr((string)$memberKey, 3) : null;
                }, array_keys($tail))) : [],
                'includes_unclassified' => $isOther && array_key_exists('unclassified', $tail),
            ];
        }
        return $output;
    }

    private static function rankedWithMonths(
        array $totals,
        array $monthsByName,
        array $labels,
        int $limit,
        float $grandTotal,
        string $otherLabel
    ): array {
        $names = array_keys($totals);
        $topNames = array_slice($names, 0, $limit);
        $otherNames = array_slice($names, $limit);
        $rows = [];
        foreach ($topNames as $key) {
            $rows[] = self::categoryRow($key, $labels[$key] ?? $key, $totals[$key], $monthsByName[$key], $grandTotal, false, []);
        }
        if (!empty($otherNames)) {
            $otherMonths = array_fill(1, 12, 0.0);
            $otherTotal = 0.0;
            foreach ($otherNames as $key) {
                $otherTotal += $totals[$key];
                foreach ($monthsByName[$key] as $month => $amount) {
                    $otherMonths[$month] += $amount;
                }
            }
            $rows[] = self::categoryRow($otherLabel, $otherLabel, $otherTotal, $otherMonths, $grandTotal, true, $otherNames);
        }
        return $rows;
    }

    private static function categoryRow(string $key, string $name, float $total, array $months, float $grandTotal, bool $isOther, array $memberKeys): array {
        $monthRows = [];
        for ($month = 1; $month <= 12; $month++) {
            $monthRows[] = [
                'month' => $month,
                'label' => date('M', mktime(0, 0, 0, $month, 1)),
                'amount' => round((float)($months[$month] ?? 0), 2),
            ];
        }
        return [
            'id' => (!$isOther && strpos($key, 'id:') === 0) ? (int)substr($key, 3) : null,
            'name' => $name,
            'amount' => round($total, 2),
            'share' => $grandTotal > 0 ? round(($total / $grandTotal) * 100, 1) : 0.0,
            'months' => $monthRows,
            'is_other' => $isOther,
            'unclassified' => !$isOther && $key === 'unclassified',
            'member_ids' => $isOther ? array_values(array_filter(array_map(function ($memberKey) {
                return strpos((string)$memberKey, 'id:') === 0 ? (int)substr((string)$memberKey, 3) : null;
            }, $memberKeys))) : [],
            'includes_unclassified' => $isOther && in_array('unclassified', $memberKeys, true),
        ];
    }
}
?>
