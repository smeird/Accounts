<?php
// Builds the concise, cross-feature snapshot used by the Instant dashboard.
require_once __DIR__ . '/../Database.php';

class InstantDashboard {
    /**
     * Return the latest useful financial snapshot. If the current month has no
     * transactions, the most recent recorded month becomes the reporting period.
     */
    public static function getSnapshot($now = null): array {
        $db = Database::getConnection();
        $now = $now ?: new DateTimeImmutable('now');
        $latestDate = self::latestTransactionDate($db);
        $currentMonthStart = $now->modify('first day of this month')->setTime(0, 0);
        $anchor = $now;

        if ($latestDate !== null && $latestDate < $currentMonthStart) {
            $anchor = $latestDate;
        }

        $monthStart = $anchor->modify('first day of this month')->setTime(0, 0);
        $nextMonthStart = $monthStart->modify('+1 month');
        $previousMonthStart = $monthStart->modify('-1 month');
        $trendStart = $monthStart->modify('-5 months');
        $isCurrentMonth = $monthStart->format('Y-m') === $currentMonthStart->format('Y-m');
        $periodProgress = $isCurrentMonth
            ? min(1, max(0.01, ((int)$now->format('j')) / (int)$now->format('t')))
            : 1.0;

        $activity = self::activityBetween($db, $trendStart, $nextMonthStart);
        $trend = self::buildTrend($activity, $trendStart, $monthStart);
        $current = self::totalsForPeriod($activity, $monthStart, $nextMonthStart);
        $previous = self::totalsForPeriod($activity, $previousMonthStart, $monthStart);
        $accounts = self::accountSummaries($db);
        $budget = self::budgetSnapshot($db, $activity, $monthStart, $nextMonthStart);
        $topCategories = self::topCategories($activity, $monthStart, $nextMonthStart);
        $recent = self::recentActivity($db, 7);
        $untaggedCount = self::untaggedCount($db);
        $staleAccounts = self::countStaleAccounts($accounts, $now);

        $totalBalance = 0.0;
        foreach ($accounts as $account) {
            $totalBalance += (float)$account['balance'];
        }

        $savingsRate = $current['income'] > 0
            ? ($current['cashflow'] / $current['income']) * 100
            : null;
        $spendingChange = self::percentageChange($current['spending'], $previous['spending']);
        $incomeChange = self::percentageChange($current['income'], $previous['income']);
        $projectedSpending = $periodProgress > 0
            ? $current['spending'] / $periodProgress
            : $current['spending'];

        $attention = self::buildAttention(
            $current,
            $budget,
            $untaggedCount,
            $staleAccounts,
            $projectedSpending,
            $previous['spending']
        );

        return [
            'period' => [
                'label' => $monthStart->format('F Y'),
                'start' => $monthStart->format('Y-m-d'),
                'is_current_month' => $isCurrentMonth,
                'progress' => round($periodProgress * 100, 1),
                'latest_transaction_date' => $latestDate ? $latestDate->format('Y-m-d') : null,
                'generated_at' => $now->format(DateTime::ATOM),
            ],
            'headline' => [
                'balance' => round($totalBalance, 2),
                'message' => self::headlineMessage($current, $monthStart),
                'tone' => self::cashflowTone($current['cashflow']),
                'spending_pace' => self::spendingPace(
                    $projectedSpending,
                    $previous['spending'],
                    $isCurrentMonth
                ),
            ],
            'metrics' => [
                'income' => round($current['income'], 2),
                'spending' => round($current['spending'], 2),
                'cashflow' => round($current['cashflow'], 2),
                'savings_rate' => $savingsRate === null ? null : round($savingsRate, 1),
                'income_change' => $incomeChange,
                'spending_change' => $spendingChange,
            ],
            'trend' => $trend,
            'top_categories' => $topCategories,
            'budget' => $budget,
            'accounts' => $accounts,
            'recent' => $recent,
            'attention' => $attention,
            'data_quality' => [
                'untagged_transactions' => $untaggedCount,
                'stale_accounts' => $staleAccounts,
            ],
        ];
    }

    private static function latestTransactionDate(PDO $db) {
        $value = $db->query('SELECT MAX(`date`) FROM `transactions`')->fetchColumn();
        if (!$value) {
            return null;
        }
        return new DateTimeImmutable((string)$value);
    }

    private static function activityBetween(PDO $db, DateTimeImmutable $start, DateTimeImmutable $end): array {
        $sql = 'SELECT t.`id`, t.`date`, t.`amount`, t.`description`, t.`category_id`, '
             . 'a.`name` AS account_name, c.`name` AS category_name, tg.`name` AS tag_name '
             . 'FROM `transactions` t '
             . 'LEFT JOIN `accounts` a ON a.`id` = t.`account_id` '
             . 'LEFT JOIN `categories` c ON c.`id` = t.`category_id` '
             . 'LEFT JOIN `tags` tg ON tg.`id` = t.`tag_id` '
             . 'WHERE t.`date` >= :start AND t.`date` < :end '
             . 'AND t.`transfer_id` IS NULL '
             . 'AND (t.`tag_id` IS NULL OR LOWER(COALESCE(tg.`name`, \'\')) != \'ignore\') '
             . 'ORDER BY t.`date` ASC, t.`id` ASC';
        $stmt = $db->prepare($sql);
        $stmt->execute([
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function buildTrend(array $activity, DateTimeImmutable $trendStart, DateTimeImmutable $monthStart): array {
        $buckets = [];
        for ($i = 0; $i < 6; $i++) {
            $date = $trendStart->modify('+' . $i . ' months');
            $key = $date->format('Y-m');
            $buckets[$key] = [
                'key' => $key,
                'label' => $date->format('M'),
                'income' => 0.0,
                'spending' => 0.0,
                'cashflow' => 0.0,
            ];
        }

        foreach ($activity as $row) {
            $key = substr((string)$row['date'], 0, 7);
            if (!isset($buckets[$key])) {
                continue;
            }
            $amount = (float)$row['amount'];
            if ($amount > 0) {
                $buckets[$key]['income'] += $amount;
            } elseif ($amount < 0) {
                $buckets[$key]['spending'] += -$amount;
            }
        }

        foreach ($buckets as &$bucket) {
            $bucket['income'] = round($bucket['income'], 2);
            $bucket['spending'] = round($bucket['spending'], 2);
            $bucket['cashflow'] = round($bucket['income'] - $bucket['spending'], 2);
        }
        unset($bucket);

        return array_values($buckets);
    }

    private static function totalsForPeriod(array $activity, DateTimeImmutable $start, DateTimeImmutable $end): array {
        $income = 0.0;
        $spending = 0.0;
        $startValue = $start->format('Y-m-d');
        $endValue = $end->format('Y-m-d');

        foreach ($activity as $row) {
            $date = (string)$row['date'];
            if ($date < $startValue || $date >= $endValue) {
                continue;
            }
            $amount = (float)$row['amount'];
            if ($amount > 0) {
                $income += $amount;
            } elseif ($amount < 0) {
                $spending += -$amount;
            }
        }

        return [
            'income' => $income,
            'spending' => $spending,
            'cashflow' => $income - $spending,
        ];
    }

    private static function accountSummaries(PDO $db): array {
        $sql = 'SELECT a.`id`, a.`name`, COALESCE(a.`ledger_balance`, 0) AS balance, '
             . 'a.`ledger_balance_date`, MAX(t.`date`) AS last_transaction '
             . 'FROM `accounts` a '
             . 'LEFT JOIN `transactions` t ON t.`account_id` = a.`id` '
             . 'GROUP BY a.`id`, a.`name`, a.`ledger_balance`, a.`ledger_balance_date` '
             . 'ORDER BY balance DESC, a.`name` ASC';
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['balance'] = round((float)$row['balance'], 2);
        }
        unset($row);
        return $rows;
    }

    private static function budgetSnapshot(
        PDO $db,
        array $activity,
        DateTimeImmutable $start,
        DateTimeImmutable $end
    ): array {
        $stmt = $db->prepare(
            'SELECT b.`id`, b.`category_id`, b.`amount`, c.`name` AS category '
            . 'FROM `budgets` b '
            . 'JOIN `categories` c ON c.`id` = b.`category_id` '
            . 'WHERE b.`month` = :month AND b.`year` = :year '
            . 'ORDER BY c.`name` ASC'
        );
        $stmt->execute([
            'month' => (int)$start->format('n'),
            'year' => (int)$start->format('Y'),
        ]);
        $budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $spentByCategory = [];
        $startValue = $start->format('Y-m-d');
        $endValue = $end->format('Y-m-d');

        foreach ($activity as $row) {
            if ((string)$row['date'] < $startValue || (string)$row['date'] >= $endValue) {
                continue;
            }
            $categoryId = isset($row['category_id']) ? (int)$row['category_id'] : 0;
            $amount = (float)$row['amount'];
            if ($categoryId > 0 && $amount < 0) {
                $spentByCategory[$categoryId] = ($spentByCategory[$categoryId] ?? 0) + (-$amount);
            }
        }

        $totalBudget = 0.0;
        $totalSpent = 0.0;
        $items = [];
        $overCount = 0;
        $watchCount = 0;
        foreach ($budgets as $budget) {
            $amount = (float)$budget['amount'];
            $spent = $spentByCategory[(int)$budget['category_id']] ?? 0.0;
            $used = $amount > 0 ? ($spent / $amount) * 100 : 0.0;
            $totalBudget += $amount;
            $totalSpent += $spent;
            $status = $used > 100 ? 'over' : ($used >= 85 ? 'watch' : 'good');
            if ($status === 'over') {
                $overCount++;
            } elseif ($status === 'watch') {
                $watchCount++;
            }
            $items[] = [
                'id' => (int)$budget['id'],
                'category_id' => (int)$budget['category_id'],
                'category' => $budget['category'],
                'amount' => round($amount, 2),
                'spent' => round($spent, 2),
                'remaining' => round($amount - $spent, 2),
                'used' => round($used, 1),
                'status' => $status,
            ];
        }

        usort($items, function ($a, $b) {
            return $b['used'] <=> $a['used'];
        });

        return [
            'total' => round($totalBudget, 2),
            'spent' => round($totalSpent, 2),
            'remaining' => round($totalBudget - $totalSpent, 2),
            'used' => $totalBudget > 0 ? round(($totalSpent / $totalBudget) * 100, 1) : null,
            'count' => count($items),
            'over_count' => $overCount,
            'watch_count' => $watchCount,
            'items' => array_slice($items, 0, 4),
        ];
    }

    private static function topCategories(
        array $activity,
        DateTimeImmutable $start,
        DateTimeImmutable $end
    ): array {
        $totals = [];
        $startValue = $start->format('Y-m-d');
        $endValue = $end->format('Y-m-d');

        foreach ($activity as $row) {
            if ((string)$row['date'] < $startValue || (string)$row['date'] >= $endValue) {
                continue;
            }
            $amount = (float)$row['amount'];
            if ($amount >= 0) {
                continue;
            }
            $name = trim((string)($row['category_name'] ?? '')) ?: 'Uncategorised';
            $totals[$name] = ($totals[$name] ?? 0) + (-$amount);
        }

        arsort($totals);
        $grandTotal = array_sum($totals);
        $output = [];
        foreach (array_slice($totals, 0, 5, true) as $name => $amount) {
            $output[] = [
                'name' => $name,
                'amount' => round($amount, 2),
                'share' => $grandTotal > 0 ? round(($amount / $grandTotal) * 100, 1) : 0,
            ];
        }
        return $output;
    }

    private static function recentActivity(PDO $db, int $limit): array {
        $limit = max(1, min(20, $limit));
        $sql = 'SELECT t.`id`, t.`date`, t.`amount`, t.`description`, '
             . 'a.`name` AS account_name, c.`name` AS category_name, '
             . 'CASE WHEN t.`transfer_id` IS NULL THEN 0 ELSE 1 END AS is_transfer '
             . 'FROM `transactions` t '
             . 'LEFT JOIN `accounts` a ON a.`id` = t.`account_id` '
             . 'LEFT JOIN `categories` c ON c.`id` = t.`category_id` '
             . 'LEFT JOIN `tags` tg ON tg.`id` = t.`tag_id` '
             . 'WHERE t.`tag_id` IS NULL OR LOWER(COALESCE(tg.`name`, \'\')) != \'ignore\' '
             . 'ORDER BY t.`date` DESC, t.`id` DESC LIMIT ' . $limit;
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['amount'] = round((float)$row['amount'], 2);
            $row['is_transfer'] = (bool)$row['is_transfer'];
        }
        unset($row);
        return $rows;
    }

    private static function untaggedCount(PDO $db): int {
        $value = $db->query('SELECT COUNT(*) FROM `transactions` WHERE `tag_id` IS NULL AND `transfer_id` IS NULL')->fetchColumn();
        return $value === false ? 0 : (int)$value;
    }

    private static function countStaleAccounts(array $accounts, DateTimeImmutable $now): int {
        $cutoff = $now->modify('-14 days')->format('Y-m-d');
        $count = 0;
        foreach ($accounts as $account) {
            $date = $account['ledger_balance_date'] ?? null;
            if (!$date || $date < $cutoff) {
                $count++;
            }
        }
        return $count;
    }

    private static function buildAttention(
        array $current,
        array $budget,
        int $untaggedCount,
        int $staleAccounts,
        float $projectedSpending,
        float $previousSpending
    ): array {
        $items = [];
        $overBudget = (int)($budget['over_count'] ?? 0);
        $nearBudget = (int)($budget['watch_count'] ?? 0);

        if ($overBudget > 0) {
            $items[] = [
                'type' => 'budget',
                'severity' => 'urgent',
                'title' => $overBudget === 1 ? 'One budget is over its limit' : $overBudget . ' budgets are over their limits',
                'detail' => 'Review the highest-pressure categories before more spending lands.',
                'href' => 'budgets.html',
            ];
        } elseif ($nearBudget > 0) {
            $items[] = [
                'type' => 'budget',
                'severity' => 'watch',
                'title' => $nearBudget === 1 ? 'One budget is close to its limit' : $nearBudget . ' budgets are close to their limits',
                'detail' => 'These categories have used at least 85% of their allowance.',
                'href' => 'budgets.html',
            ];
        } elseif ($budget['count'] === 0) {
            $items[] = [
                'type' => 'budget',
                'severity' => 'info',
                'title' => 'No budgets are set for this month',
                'detail' => 'Add limits to turn spending into an early-warning signal.',
                'href' => 'budgets.html',
            ];
        }

        if ($current['cashflow'] < 0) {
            $items[] = [
                'type' => 'cashflow',
                'severity' => 'urgent',
                'title' => 'Outgoings are ahead of income',
                'detail' => 'Current cash flow is negative by £' . number_format(abs($current['cashflow']), 0),
                'href' => 'monthly_dashboard.html',
            ];
        } elseif ($previousSpending > 0 && $projectedSpending > $previousSpending * 1.12) {
            $items[] = [
                'type' => 'cashflow',
                'severity' => 'watch',
                'title' => 'Spending is running faster than last month',
                'detail' => 'At this pace, this month could finish above the previous one.',
                'href' => 'monthly_dashboard.html',
            ];
        }

        if ($untaggedCount > 0) {
            $items[] = [
                'type' => 'tags',
                'severity' => $untaggedCount >= 25 ? 'watch' : 'info',
                'title' => number_format($untaggedCount) . ' transactions need tags',
                'detail' => 'Completing these improves every category and budget view.',
                'href' => 'missing_tags.html',
            ];
        }

        if ($staleAccounts > 0) {
            $items[] = [
                'type' => 'accounts',
                'severity' => 'info',
                'title' => $staleAccounts === 1 ? 'One account balance may be stale' : $staleAccounts . ' account balances may be stale',
                'detail' => 'Import a recent statement to refresh the overall position.',
                'href' => 'upload.html',
            ];
        }

        if (empty($items)) {
            $items[] = [
                'type' => 'clear',
                'severity' => 'good',
                'title' => 'Nothing urgent needs attention',
                'detail' => 'Cash flow, budgets, balances and tagging all look healthy.',
                'href' => 'monthly_dashboard.html',
            ];
        }

        return array_slice($items, 0, 4);
    }

    private static function headlineMessage(array $current, DateTimeImmutable $monthStart): string {
        if ($current['income'] === 0.0 && $current['spending'] === 0.0) {
            return 'No activity has been recorded for ' . $monthStart->format('F') . ' yet.';
        }
        if ($current['cashflow'] >= 0) {
            return 'You are £' . number_format($current['cashflow'], 0) . ' ahead for ' . $monthStart->format('F') . '.';
        }
        return 'Spending is £' . number_format(abs($current['cashflow']), 0) . ' above income this month.';
    }

    private static function cashflowTone(float $cashflow): string {
        if ($cashflow > 0) {
            return 'positive';
        }
        if ($cashflow < 0) {
            return 'negative';
        }
        return 'neutral';
    }

    private static function spendingPace(float $projected, float $previous, bool $isCurrentMonth): array {
        if ($previous <= 0) {
            return ['tone' => 'neutral', 'label' => 'Not enough history to compare spending pace'];
        }
        $difference = (($projected - $previous) / $previous) * 100;
        $prefix = $isCurrentMonth ? 'Projected to finish ' : '';
        if (abs($difference) < 5) {
            return ['tone' => 'neutral', 'label' => 'Spending is broadly in line with last month'];
        }
        if ($difference > 0) {
            return [
                'tone' => 'negative',
                'label' => $prefix . round(abs($difference)) . '% above last month',
            ];
        }
        return [
            'tone' => 'positive',
            'label' => $prefix . round(abs($difference)) . '% below last month',
        ];
    }

    private static function percentageChange(float $current, float $previous): ?float {
        if ($previous == 0.0) {
            return null;
        }
        return round((($current - $previous) / $previous) * 100, 1);
    }
}

?>
