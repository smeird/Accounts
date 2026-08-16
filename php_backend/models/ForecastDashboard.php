<?php
// Builds a transparent 12-period financial forecast from observed transactions.
require_once __DIR__ . '/../Database.php';

class ForecastDashboard {
    private const HISTORY_MONTHS = 24;
    private const BASELINE_MONTHS = 6;
    private const FORECAST_MONTHS = 12;

    public static function getSnapshot($now = null): array {
        $db = Database::getConnection();
        $now = $now ?: new DateTimeImmutable('now');
        $position = self::accountPosition($db);
        $latestTransaction = self::latestIncludedTransactionDate($db);
        $anchor = self::latestDate($latestTransaction, $position['as_of']);
        if ($anchor === null) {
            $anchor = $now;
        }

        $anchor = $anchor->setTime(0, 0);
        $anchorMonth = $anchor->modify('first day of this month');
        $isMonthComplete = (int)$anchor->format('j') >= (int)$anchor->format('t');
        $completeBefore = $isMonthComplete ? $anchorMonth->modify('+1 month') : $anchorMonth;
        $forecastStart = $isMonthComplete ? $anchorMonth->modify('+1 month') : $anchorMonth;
        $historyStart = $completeBefore->modify('-' . self::HISTORY_MONTHS . ' months');
        $activityEnd = $anchor->modify('+1 day');
        $activity = self::activityBetween($db, $historyStart, $activityEnd);
        $completeActivity = array_values(array_filter($activity, function ($row) use ($completeBefore) {
            return (string)$row['date'] < $completeBefore->format('Y-m-d');
        }));

        $monthly = self::aggregateMonths($completeActivity);
        $activeMonths = array_values($monthly);
        $recentMonths = array_slice($activeMonths, -self::BASELINE_MONTHS);
        $incomeValues = array_map(function ($month) { return (float)$month['income']; }, $recentMonths);
        $spendingValues = array_map(function ($month) { return (float)$month['spending']; }, $recentMonths);
        $incomeBaseline = self::trimmedMean($incomeValues);
        $spendingBaseline = self::trimmedMean($spendingValues);
        $incomeStdDev = self::standardDeviation($incomeValues);
        $spendingStdDev = self::standardDeviation($spendingValues);
        $firstPeriodFactor = $isMonthComplete
            ? 1.0
            : max(0.0, ((int)$anchor->format('t') - (int)$anchor->format('j')) / (int)$anchor->format('t'));

        $forecast = self::buildForecast(
            $forecastStart,
            $firstPeriodFactor,
            $incomeBaseline,
            $spendingBaseline,
            $incomeStdDev,
            $spendingStdDev,
            $activeMonths,
            (float)$position['total']
        );
        $metrics = self::forecastMetrics($forecast, (float)$position['total']);
        $topCategories = self::projectCategories($completeActivity, $metrics['spending']);
        $confidence = self::confidence(count($activeMonths), count($activity));
        $history = self::historySeries($monthly, $completeBefore);
        $historyStartDate = !empty($activity) ? (string)$activity[0]['date'] : null;

        return [
            'has_data' => count($activeMonths) > 0,
            'generated_at' => $now->format(DateTime::ATOM),
            'period' => [
                'anchor_date' => $anchor->format('Y-m-d'),
                'start' => $forecast[0]['date'],
                'end' => $forecast[count($forecast) - 1]['month_end'],
                'label' => $forecast[0]['label'] . ' to ' . $forecast[count($forecast) - 1]['label'],
                'first_period_is_partial' => !$isMonthComplete,
            ],
            'coverage' => [
                'history_start' => $historyStartDate,
                'history_end' => $completeBefore->modify('-1 day')->format('Y-m-d'),
                'latest_transaction_date' => $latestTransaction ? $latestTransaction->format('Y-m-d') : null,
                'balance_as_of' => $position['as_of'],
                'transaction_count' => count($activity),
                'active_months' => count($activeMonths),
                'modelled_months' => count($recentMonths),
                'confidence' => $confidence,
            ],
            'metrics' => $metrics,
            'history' => $history,
            'forecast' => $forecast,
            'top_categories' => $topCategories,
            'insights' => self::buildInsights($metrics, $topCategories, $confidence),
            'methodology' => [
                'approach' => 'A trimmed average of the latest six complete active months, adjusted for calendar-month seasonality when at least twelve months are available.',
                'assumptions' => [
                    'Recent income and spending patterns continue unless seasonal history suggests otherwise.',
                    'The first forecast period covers only the days after the latest balance or transaction date.',
                    'Conservative and optimistic paths vary recent income and spending using observed month-to-month volatility.',
                ],
                'exclusions' => 'Confirmed account transfers and IGNORE-tagged transactions are excluded so moving money between accounts is not treated as income or spending.',
                'notice' => 'This is a directional planning forecast based on recorded activity, not financial advice or a guarantee.',
            ],
        ];
    }

    private static function latestIncludedTransactionDate(PDO $db): ?DateTimeImmutable {
        $value = $db->query(
            'SELECT MAX(t.`date`) FROM `transactions` t '
            . 'LEFT JOIN `tags` tg ON tg.`id` = t.`tag_id` '
            . 'WHERE t.`transfer_id` IS NULL '
            . 'AND (t.`tag_id` IS NULL OR LOWER(COALESCE(tg.`name`, \'\')) != \'ignore\')'
        )->fetchColumn();
        return $value ? new DateTimeImmutable((string)$value) : null;
    }

    private static function latestDate(?DateTimeImmutable $transactionDate, ?string $balanceDate): ?DateTimeImmutable {
        $balanceAsOf = $balanceDate ? new DateTimeImmutable($balanceDate) : null;
        if ($transactionDate === null) {
            return $balanceAsOf;
        }
        if ($balanceAsOf === null) {
            return $transactionDate;
        }
        return $balanceAsOf > $transactionDate ? $balanceAsOf : $transactionDate;
    }

    private static function accountPosition(PDO $db): array {
        $rows = $db->query(
            'SELECT COALESCE(`ledger_balance`, 0) AS balance, `ledger_balance_date` FROM `accounts` WHERE `closed` = 0'
        )->fetchAll(PDO::FETCH_ASSOC);
        $total = 0.0;
        $asOf = null;
        foreach ($rows as $row) {
            $total += (float)$row['balance'];
            if (!empty($row['ledger_balance_date']) && ($asOf === null || $row['ledger_balance_date'] > $asOf)) {
                $asOf = (string)$row['ledger_balance_date'];
            }
        }
        return ['total' => round($total, 2), 'as_of' => $asOf];
    }

    private static function activityBetween(PDO $db, DateTimeImmutable $start, DateTimeImmutable $end): array {
        $stmt = $db->prepare(
            'SELECT t.`date`, t.`amount`, COALESCE(c.`name`, \'Uncategorised\') AS category '
            . 'FROM `transactions` t '
            . 'LEFT JOIN `categories` c ON c.`id` = t.`category_id` '
            . 'LEFT JOIN `tags` tg ON tg.`id` = t.`tag_id` '
            . 'WHERE t.`date` >= :start AND t.`date` < :end '
            . 'AND t.`transfer_id` IS NULL '
            . 'AND (t.`tag_id` IS NULL OR LOWER(COALESCE(tg.`name`, \'\')) != \'ignore\') '
            . 'ORDER BY t.`date` ASC, t.`id` ASC'
        );
        $stmt->execute(['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function aggregateMonths(array $activity): array {
        $months = [];
        foreach ($activity as $row) {
            $key = substr((string)$row['date'], 0, 7);
            if (!isset($months[$key])) {
                $date = new DateTimeImmutable($key . '-01');
                $months[$key] = [
                    'key' => $key,
                    'date' => $date->format('Y-m-d'),
                    'label' => $date->format('M Y'),
                    'income' => 0.0,
                    'spending' => 0.0,
                    'cashflow' => 0.0,
                    'transactions' => 0,
                ];
            }
            $amount = (float)$row['amount'];
            if ($amount > 0) {
                $months[$key]['income'] += $amount;
            } elseif ($amount < 0) {
                $months[$key]['spending'] += -$amount;
            }
            $months[$key]['transactions']++;
        }
        ksort($months);
        foreach ($months as &$month) {
            $month['income'] = round($month['income'], 2);
            $month['spending'] = round($month['spending'], 2);
            $month['cashflow'] = round($month['income'] - $month['spending'], 2);
        }
        unset($month);
        return $months;
    }

    private static function trimmedMean(array $values): float {
        if (empty($values)) {
            return 0.0;
        }
        sort($values, SORT_NUMERIC);
        if (count($values) >= 5) {
            array_shift($values);
            array_pop($values);
        }
        return array_sum($values) / count($values);
    }

    private static function standardDeviation(array $values): float {
        if (count($values) < 2) {
            return 0.0;
        }
        $mean = array_sum($values) / count($values);
        $variance = array_sum(array_map(function ($value) use ($mean) {
            return pow($value - $mean, 2);
        }, $values)) / count($values);
        return sqrt($variance);
    }

    private static function seasonality(array $months, int $calendarMonth, string $metric): float {
        if (count($months) < 12) {
            return 1.0;
        }
        $overallValues = array_map(function ($month) use ($metric) {
            return (float)$month[$metric];
        }, $months);
        $overall = array_sum($overallValues) / count($overallValues);
        if ($overall <= 0.005) {
            return 1.0;
        }
        $matching = array_values(array_filter($months, function ($month) use ($calendarMonth) {
            return (int)substr((string)$month['key'], 5, 2) === $calendarMonth;
        }));
        if (empty($matching)) {
            return 1.0;
        }
        $matchingTotal = array_sum(array_map(function ($month) use ($metric) {
            return (float)$month[$metric];
        }, $matching));
        return min(1.3, max(0.7, ($matchingTotal / count($matching)) / $overall));
    }

    private static function buildForecast(
        DateTimeImmutable $start,
        float $firstFactor,
        float $incomeBaseline,
        float $spendingBaseline,
        float $incomeStdDev,
        float $spendingStdDev,
        array $history,
        float $startingBalance
    ): array {
        $forecast = [];
        $expectedBalance = $startingBalance;
        $conservativeBalance = $startingBalance;
        $optimisticBalance = $startingBalance;

        for ($i = 0; $i < self::FORECAST_MONTHS; $i++) {
            $date = $start->modify('+' . $i . ' months');
            $factor = $i === 0 ? $firstFactor : 1.0;
            $income = $incomeBaseline * self::seasonality($history, (int)$date->format('n'), 'income') * $factor;
            $spending = $spendingBaseline * self::seasonality($history, (int)$date->format('n'), 'spending') * $factor;
            $incomeVariation = $incomeStdDev * 0.35 * $factor;
            $spendingVariation = $spendingStdDev * 0.35 * $factor;
            $cashflow = $income - $spending;
            $conservativeCashflow = max(0, $income - $incomeVariation) - ($spending + $spendingVariation);
            $optimisticCashflow = ($income + $incomeVariation) - max(0, $spending - $spendingVariation);
            $expectedBalance += $cashflow;
            $conservativeBalance += $conservativeCashflow;
            $optimisticBalance += $optimisticCashflow;

            $forecast[] = [
                'key' => $date->format('Y-m'),
                'date' => $date->format('Y-m-d'),
                'month_end' => $date->modify('last day of this month')->format('Y-m-d'),
                'label' => $date->format('M Y'),
                'factor' => round($factor, 4),
                'partial' => $factor < 0.9999,
                'income' => round($income, 2),
                'spending' => round($spending, 2),
                'cashflow' => round($cashflow, 2),
                'expected_balance' => round($expectedBalance, 2),
                'conservative_balance' => round($conservativeBalance, 2),
                'optimistic_balance' => round($optimisticBalance, 2),
            ];
        }
        return $forecast;
    }

    private static function forecastMetrics(array $forecast, float $startingBalance): array {
        $income = array_sum(array_column($forecast, 'income'));
        $spending = array_sum(array_column($forecast, 'spending'));
        $cashflow = array_sum(array_column($forecast, 'cashflow'));
        $last = $forecast[count($forecast) - 1];
        $negativeMonths = count(array_filter($forecast, function ($month) {
            return (float)$month['cashflow'] < 0;
        }));
        return [
            'starting_balance' => round($startingBalance, 2),
            'income' => round($income, 2),
            'spending' => round($spending, 2),
            'cashflow' => round($cashflow, 2),
            'ending_balance' => round((float)$last['expected_balance'], 2),
            'conservative_ending_balance' => round((float)$last['conservative_balance'], 2),
            'optimistic_ending_balance' => round((float)$last['optimistic_balance'], 2),
            'savings_rate' => $income > 0 ? round(($cashflow / $income) * 100, 1) : null,
            'negative_months' => $negativeMonths,
        ];
    }

    private static function projectCategories(array $activity, float $projectedSpending): array {
        $activeMonthKeys = array_values(array_unique(array_map(function ($row) {
            return substr((string)$row['date'], 0, 7);
        }, $activity)));
        $recentMonthKeys = array_flip(array_slice($activeMonthKeys, -12));
        $categoryTotals = [];
        $total = 0.0;
        foreach ($activity as $row) {
            if (!isset($recentMonthKeys[substr((string)$row['date'], 0, 7)])) {
                continue;
            }
            $amount = (float)$row['amount'];
            if ($amount >= 0) {
                continue;
            }
            $category = (string)$row['category'];
            $expense = -$amount;
            $categoryTotals[$category] = ($categoryTotals[$category] ?? 0.0) + $expense;
            $total += $expense;
        }
        arsort($categoryTotals);
        $maximum = !empty($categoryTotals) ? max($categoryTotals) : 0.0;
        $result = [];
        foreach (array_slice($categoryTotals, 0, 6, true) as $name => $amount) {
            $share = $total > 0 ? $amount / $total : 0.0;
            $result[] = [
                'name' => $name,
                'historical_amount' => round($amount, 2),
                'share' => round($share * 100, 1),
                'relative' => $maximum > 0 ? round(($amount / $maximum) * 100, 1) : 0.0,
                'projected_amount' => round($projectedSpending * $share, 2),
            ];
        }
        return $result;
    }

    private static function historySeries(array $months, DateTimeImmutable $completeBefore): array {
        $result = [];
        $start = $completeBefore->modify('-6 months');
        for ($i = 0; $i < 6; $i++) {
            $date = $start->modify('+' . $i . ' months');
            $key = $date->format('Y-m');
            $result[] = $months[$key] ?? [
                'key' => $key,
                'date' => $date->format('Y-m-d'),
                'label' => $date->format('M Y'),
                'income' => 0.0,
                'spending' => 0.0,
                'cashflow' => 0.0,
                'transactions' => 0,
            ];
        }
        return $result;
    }

    private static function confidence(int $activeMonths, int $transactions): string {
        if ($activeMonths >= 18 && $transactions >= 200) {
            return 'high';
        }
        if ($activeMonths >= 9 && $transactions >= 50) {
            return 'medium';
        }
        return 'low';
    }

    private static function buildInsights(array $metrics, array $categories, string $confidence): array {
        $insights = [];
        $cashflow = (float)$metrics['cashflow'];
        $insights[] = [
            'tone' => $cashflow >= 0 ? 'good' : 'urgent',
            'icon' => $cashflow >= 0 ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down',
            'title' => $cashflow >= 0 ? 'The baseline adds to your position' : 'The baseline draws down your position',
            'detail' => 'Expected net movement over the forecast is £' . number_format(abs($cashflow), 0) . ($cashflow >= 0 ? ' positive.' : ' negative.'),
        ];
        if ((int)$metrics['negative_months'] > 0) {
            $count = (int)$metrics['negative_months'];
            $insights[] = [
                'tone' => 'watch',
                'icon' => 'fa-calendar-minus',
                'title' => $count . ' forecast month' . ($count === 1 ? '' : 's') . ' run cash-flow negative',
                'detail' => 'Use the monthly path to identify when extra headroom may be useful.',
            ];
        } else {
            $insights[] = [
                'tone' => 'good',
                'icon' => 'fa-calendar-check',
                'title' => 'Every forecast month stays cash-flow positive',
                'detail' => 'The current pattern does not show a monthly deficit in the baseline path.',
            ];
        }
        if (!empty($categories)) {
            $insights[] = [
                'tone' => 'neutral',
                'icon' => 'fa-layer-group',
                'title' => $categories[0]['name'] . ' remains the largest spending driver',
                'detail' => number_format((float)$categories[0]['share'], 1) . '% of observed spending sits in this category.',
            ];
        }
        $insights[] = [
            'tone' => $confidence === 'low' ? 'watch' : 'neutral',
            'icon' => 'fa-wave-square',
            'title' => ucfirst($confidence) . ' forecast confidence',
            'detail' => $confidence === 'low'
                ? 'More complete transaction history will make the baseline more representative.'
                : 'The model has enough recorded history to distinguish recent pattern from normal variation.',
        ];
        return array_slice($insights, 0, 4);
    }
}
?>
