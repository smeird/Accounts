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
            'SELECT t.`date`, t.`amount`, '
            . 'COALESCE(c.`name`, \'Uncategorised\') AS category, '
            . 'COALESCE(ts.`name`, cs.`name`, \'Not segmented\') AS segment, '
            . 'COALESCE(tg.`name`, \'Untagged\') AS tag '
            . 'FROM `transactions` t '
            . 'LEFT JOIN `categories` c ON c.`id` = t.`category_id` '
            . 'LEFT JOIN `segments` ts ON ts.`id` = t.`segment_id` '
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

        foreach ($activity as $row) {
            $amount = (float)$row['amount'];
            if ($amount >= 0) {
                continue;
            }
            $expense = -$amount;
            $month = (int)substr((string)$row['date'], 5, 2);
            $category = (string)$row['category'];
            $segment = (string)$row['segment'];
            $tag = (string)$row['tag'];
            $categoryTotals[$category] = ($categoryTotals[$category] ?? 0.0) + $expense;
            if (!isset($categoryMonths[$category])) {
                $categoryMonths[$category] = array_fill(1, 12, 0.0);
            }
            if ($month >= 1 && $month <= 12) {
                $categoryMonths[$category][$month] += $expense;
            }
            $segmentTotals[$segment] = ($segmentTotals[$segment] ?? 0.0) + $expense;
            $tagTotals[$tag] = ($tagTotals[$tag] ?? 0.0) + $expense;
        }

        arsort($categoryTotals);
        arsort($segmentTotals);
        arsort($tagTotals);

        return [
            'categories' => self::rankedWithMonths(
                $categoryTotals,
                $categoryMonths,
                self::CATEGORY_LIMIT,
                $totalSpending,
                'Other spending'
            ),
            'segments' => self::ranked($segmentTotals, self::SEGMENT_LIMIT, $totalSpending, 'Other segments'),
            'tags' => self::ranked($tagTotals, self::TAG_LIMIT, $totalSpending, 'Other tags'),
        ];
    }

    private static function ranked(array $totals, int $limit, float $grandTotal, string $otherLabel): array {
        $head = array_slice($totals, 0, $limit, true);
        $tail = array_slice($totals, $limit, null, true);
        if (!empty($tail)) {
            $head[$otherLabel] = array_sum($tail);
        }
        $output = [];
        foreach ($head as $name => $amount) {
            $output[] = [
                'name' => (string)$name,
                'amount' => round((float)$amount, 2),
                'share' => $grandTotal > 0 ? round(((float)$amount / $grandTotal) * 100, 1) : 0.0,
                'is_other' => !empty($tail) && $name === $otherLabel,
            ];
        }
        return $output;
    }

    private static function rankedWithMonths(
        array $totals,
        array $monthsByName,
        int $limit,
        float $grandTotal,
        string $otherLabel
    ): array {
        $names = array_keys($totals);
        $topNames = array_slice($names, 0, $limit);
        $otherNames = array_slice($names, $limit);
        $rows = [];
        foreach ($topNames as $name) {
            $rows[] = self::categoryRow($name, $totals[$name], $monthsByName[$name], $grandTotal, false);
        }
        if (!empty($otherNames)) {
            $otherMonths = array_fill(1, 12, 0.0);
            $otherTotal = 0.0;
            foreach ($otherNames as $name) {
                $otherTotal += $totals[$name];
                foreach ($monthsByName[$name] as $month => $amount) {
                    $otherMonths[$month] += $amount;
                }
            }
            $rows[] = self::categoryRow($otherLabel, $otherTotal, $otherMonths, $grandTotal, true);
        }
        return $rows;
    }

    private static function categoryRow(string $name, float $total, array $months, float $grandTotal, bool $isOther): array {
        $monthRows = [];
        for ($month = 1; $month <= 12; $month++) {
            $monthRows[] = [
                'month' => $month,
                'label' => date('M', mktime(0, 0, 0, $month, 1)),
                'amount' => round((float)($months[$month] ?? 0), 2),
            ];
        }
        return [
            'name' => $name,
            'amount' => round($total, 2),
            'share' => $grandTotal > 0 ? round(($total / $grandTotal) * 100, 1) : 0.0,
            'months' => $monthRows,
            'is_other' => $isOther,
        ];
    }
}
?>
