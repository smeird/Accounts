<?php
// Builds the annual financial overview used by the Yearly dashboard.
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/Tag.php';

class YearlyDashboard {
    private static function percentChange(float $current, float $previous): ?float {
        if (abs($previous) < 0.005) {
            return null;
        }
        return round((($current - $previous) / abs($previous)) * 100, 1);
    }

    private static function emptyMonths(): array {
        $months = [];
        for ($month = 1; $month <= 12; $month++) {
            $months[$month] = [
                'month' => $month,
                'label' => date('M', mktime(0, 0, 0, $month, 1)),
                'income' => 0.0,
                'spending' => 0.0,
                'cashflow' => 0.0
            ];
        }
        return $months;
    }

    private static function rowsForRange(string $start, string $end): array {
        $db = Database::getConnection();
        $ignore = Tag::getIgnoreId();
        $stmt = $db->prepare(
            'SELECT t.`date`, t.`amount`, c.`id` AS category_id, COALESCE(c.`name`, \'Uncategorised\') AS category '
            . 'FROM `transactions` t '
            . 'LEFT JOIN `categories` c ON c.`id` = t.`category_id` '
            . 'WHERE t.`date` >= :start AND t.`date` < :end '
            . 'AND t.`transfer_id` IS NULL '
            . 'AND (t.`tag_id` IS NULL OR t.`tag_id` != :ignore) '
            . 'ORDER BY t.`date` ASC'
        );
        $stmt->execute(['start' => $start, 'end' => $end, 'ignore' => $ignore]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function aggregate(array $rows): array {
        $months = self::emptyMonths();
        $categories = [];
        $income = 0.0;
        $spending = 0.0;

        foreach ($rows as $row) {
            $amount = (float)$row['amount'];
            $month = (int)substr((string)$row['date'], 5, 2);
            if ($month < 1 || $month > 12) {
                continue;
            }

            if ($amount >= 0) {
                $income += $amount;
                $months[$month]['income'] += $amount;
            } else {
                $expense = abs($amount);
                $spending += $expense;
                $months[$month]['spending'] += $expense;
                $categoryId = $row['category_id'] !== null ? (int)$row['category_id'] : null;
                $category = (string)$row['category'];
                $categoryKey = $categoryId === null ? 'unclassified' : 'id:' . $categoryId;
                if (!isset($categories[$categoryKey])) $categories[$categoryKey] = ['id' => $categoryId, 'name' => $category, 'amount' => 0.0];
                $categories[$categoryKey]['amount'] += $expense;
            }
        }

        foreach ($months as &$month) {
            $month['income'] = round($month['income'], 2);
            $month['spending'] = round($month['spending'], 2);
            $month['cashflow'] = round($month['income'] - $month['spending'], 2);
        }
        unset($month);

        uasort($categories, function ($left, $right) { return $right['amount'] <=> $left['amount']; });
        return [
            'income' => round($income, 2),
            'spending' => round($spending, 2),
            'cashflow' => round($income - $spending, 2),
            'savings_rate' => $income > 0 ? round((($income - $spending) / $income) * 100, 1) : 0.0,
            'months' => array_values($months),
            'categories' => $categories
        ];
    }

    public static function getSnapshot(int $year): array {
        if ($year < 1900 || $year > 2200) {
            throw new InvalidArgumentException('Invalid year');
        }

        $current = self::aggregate(self::rowsForRange("$year-01-01", ($year + 1) . '-01-01'));
        $previous = self::aggregate(self::rowsForRange(($year - 1) . '-01-01', "$year-01-01"));
        $activeMonths = array_values(array_filter($current['months'], function ($month) {
            return $month['income'] > 0 || $month['spending'] > 0;
        }));
        $latestActiveMonth = !empty($activeMonths) ? max(array_column($activeMonths, 'month')) : 12;
        $previousComparableMonths = array_slice($previous['months'], 0, $latestActiveMonth);
        $previousComparableIncome = array_sum(array_column($previousComparableMonths, 'income'));
        $previousComparableSpending = array_sum(array_column($previousComparableMonths, 'spending'));
        $previousComparableCashflow = $previousComparableIncome - $previousComparableSpending;

        $quarters = [];
        for ($quarter = 1; $quarter <= 4; $quarter++) {
            $slice = array_slice($current['months'], ($quarter - 1) * 3, 3);
            $quarterIncome = array_sum(array_column($slice, 'income'));
            $quarterSpending = array_sum(array_column($slice, 'spending'));
            $quarters[] = [
                'quarter' => $quarter,
                'label' => 'Q' . $quarter,
                'income' => round($quarterIncome, 2),
                'spending' => round($quarterSpending, 2),
                'cashflow' => round($quarterIncome - $quarterSpending, 2)
            ];
        }

        $topCategories = [];
        $categoryMax = !empty($current['categories']) ? max(array_column($current['categories'], 'amount')) : 0;
        foreach (array_slice($current['categories'], 0, 7, true) as $category) {
            $amount = $category['amount'];
            $topCategories[] = [
                'id' => $category['id'],
                'name' => $category['name'],
                'amount' => round($amount, 2),
                'share' => $current['spending'] > 0 ? round(($amount / $current['spending']) * 100, 1) : 0,
                'relative' => $categoryMax > 0 ? round(($amount / $categoryMax) * 100, 1) : 0
            ];
        }

        $bestMonth = null;
        $highestSpendMonth = null;
        $negativeMonths = 0;
        foreach ($activeMonths as $month) {
            if ($bestMonth === null || $month['cashflow'] > $bestMonth['cashflow']) {
                $bestMonth = $month;
            }
            if ($highestSpendMonth === null || $month['spending'] > $highestSpendMonth['spending']) {
                $highestSpendMonth = $month;
            }
            if ($month['cashflow'] < 0) {
                $negativeMonths++;
            }
        }

        $insights = [];
        if ($bestMonth !== null) {
            $insights[] = [
                'tone' => 'good',
                'icon' => 'fa-trophy',
                'title' => $bestMonth['label'] . ' was your strongest cash-flow month',
                'detail' => 'You kept £' . number_format($bestMonth['cashflow'], 0) . ' after spending.'
            ];
        }
        if ($highestSpendMonth !== null) {
            $insights[] = [
                'tone' => 'watch',
                'icon' => 'fa-arrow-trend-up',
                'title' => $highestSpendMonth['label'] . ' carried the highest spend',
                'detail' => 'Outgoings reached £' . number_format($highestSpendMonth['spending'], 0) . ' that month.'
            ];
        }
        if (!empty($topCategories)) {
            $insights[] = [
                'tone' => 'neutral',
                'icon' => 'fa-layer-group',
                'title' => $topCategories[0]['name'] . ' was the biggest spending driver',
                'detail' => $topCategories[0]['share'] . '% of annual outgoings landed here.'
            ];
        }
        if ($negativeMonths > 0) {
            $insights[] = [
                'tone' => 'urgent',
                'icon' => 'fa-triangle-exclamation',
                'title' => $negativeMonths . ' month' . ($negativeMonths === 1 ? '' : 's') . ' ended cash-flow negative',
                'detail' => 'Review those periods for one-offs or recurring pressure.'
            ];
        }

        return [
            'year' => $year,
            'metrics' => [
                'income' => $current['income'],
                'spending' => $current['spending'],
                'cashflow' => $current['cashflow'],
                'savings_rate' => $current['savings_rate'],
                'active_months' => count($activeMonths),
                'negative_months' => $negativeMonths
            ],
            'comparison' => [
                'year' => $year - 1,
                'through_month' => $latestActiveMonth,
                'income' => self::percentChange($current['income'], $previousComparableIncome),
                'spending' => self::percentChange($current['spending'], $previousComparableSpending),
                'cashflow' => self::percentChange($current['cashflow'], $previousComparableCashflow)
            ],
            'months' => $current['months'],
            'quarters' => $quarters,
            'top_categories' => $topCategories,
            'insights' => array_slice($insights, 0, 4)
        ];
    }
}
?>
