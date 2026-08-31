<?php
// Builds a calendar-normalised daily expenditure history by segment.
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/Tag.php';

class DailyBurn {
    private static function validateDate(string $date): DateTimeImmutable {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Dates must use YYYY-MM-DD format');
        }
        return $parsed;
    }

    /** @return array<int,array<string,mixed>> */
    private static function expenseRows(string $start, string $end): array {
        $db = Database::getConnection();
        $ignore = Tag::getIgnoreId();
        $stmt = $db->prepare(
            'SELECT t.`id`, t.`date`, ABS(t.`amount`) AS expense, '
            . 'cs.`id` AS segment_id, '
            . 'COALESCE(cs.`name`, \'Unsegmented\') AS segment_name '
            . 'FROM `transactions` t '
            . 'LEFT JOIN `categories` c ON c.`id` = t.`category_id` '
            . 'LEFT JOIN `segments` cs ON cs.`id` = c.`segment_id` '
            . 'WHERE t.`date` >= :start AND t.`date` <= :end '
            . 'AND t.`amount` < 0 '
            . 'AND t.`transfer_id` IS NULL '
            . 'AND (t.`tag_id` IS NULL OR t.`tag_id` != :ignore) '
            . 'ORDER BY t.`date`, t.`id`'
        );
        $stmt->execute(['start' => $start, 'end' => $end, 'ignore' => $ignore]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function segmentKey(?int $id, string $name): string {
        return $id === null ? 'unsegmented' : 'id:' . $id;
    }

    private static function monthSeed(DateTimeImmutable $month): array {
        return [
            'key' => $month->format('Y-m'),
            'label' => $month->format('M y'),
            'days_in_month' => (int)$month->format('t'),
            'spending' => 0.0,
            'daily_burn' => 0.0,
            'segments' => [],
        ];
    }

    public static function getSnapshot(string $start, string $end): array {
        $startDate = self::validateDate($start);
        $endDate = self::validateDate($end);
        if ($startDate > $endDate) {
            throw new InvalidArgumentException('The start date must be before the end date');
        }
        if ((int)$startDate->diff($endDate)->format('%a') > 3660) {
            throw new InvalidArgumentException('Daily burn history is limited to ten years');
        }

        $months = [];
        $firstMonth = $startDate->modify('first day of this month');
        $lastMonth = $endDate->modify('first day of this month');
        for ($cursor = $firstMonth; $cursor <= $lastMonth; $cursor = $cursor->modify('+1 month')) {
            $months[$cursor->format('Y-m')] = self::monthSeed($cursor);
        }

        $dailyActual = [];
        for ($cursor = $startDate; $cursor <= $endDate; $cursor = $cursor->modify('+1 day')) {
            $dailyActual[$cursor->format('Y-m-d')] = 0.0;
        }

        $segments = [];
        $transactionCount = 0;
        foreach (self::expenseRows($start, $end) as $row) {
            $date = (string)$row['date'];
            $monthKey = substr($date, 0, 7);
            if (!isset($months[$monthKey]) || !isset($dailyActual[$date])) {
                continue;
            }
            $expense = (float)$row['expense'];
            $segmentId = $row['segment_id'] === null ? null : (int)$row['segment_id'];
            $segmentName = trim((string)$row['segment_name']) ?: 'Unsegmented';
            $segmentKey = self::segmentKey($segmentId, $segmentName);
            $transactionCount++;
            $dailyActual[$date] += $expense;
            $months[$monthKey]['spending'] += $expense;

            if (!isset($months[$monthKey]['segments'][$segmentKey])) {
                $months[$monthKey]['segments'][$segmentKey] = [
                    'id' => $segmentId,
                    'name' => $segmentName,
                    'amount' => 0.0,
                    'daily_burn' => 0.0,
                ];
            }
            $months[$monthKey]['segments'][$segmentKey]['amount'] += $expense;

            if (!isset($segments[$segmentKey])) {
                $segments[$segmentKey] = [
                    'id' => $segmentId,
                    'name' => $segmentName,
                    'total_spending' => 0.0,
                    'monthly_burn_sum' => 0.0,
                    'average_daily_burn' => 0.0,
                    'latest_daily_burn' => 0.0,
                    'share' => 0.0,
                    'unsegmented' => $segmentId === null,
                ];
            }
            $segments[$segmentKey]['total_spending'] += $expense;
        }

        $burnSum = 0.0;
        foreach ($months as &$month) {
            $days = (int)$month['days_in_month'];
            $month['spending'] = round((float)$month['spending'], 2);
            $month['daily_burn'] = round($days > 0 ? $month['spending'] / $days : 0.0, 2);
            $burnSum += $month['daily_burn'];
            foreach ($month['segments'] as $key => &$segment) {
                $segment['amount'] = round((float)$segment['amount'], 2);
                $segment['daily_burn'] = round($days > 0 ? $segment['amount'] / $days : 0.0, 2);
                $segments[$key]['monthly_burn_sum'] += $segment['daily_burn'];
            }
            unset($segment);
            $month['segments'] = array_values($month['segments']);
        }
        unset($month);

        $monthCount = count($months);
        $latestMonth = $monthCount ? end($months) : null;
        $latestBySegment = [];
        if (is_array($latestMonth)) {
            foreach ($latestMonth['segments'] as $segment) {
                $latestBySegment[self::segmentKey($segment['id'], $segment['name'])] = (float)$segment['daily_burn'];
            }
        }

        $totalSpending = array_sum(array_column($months, 'spending'));
        foreach ($segments as $key => &$segment) {
            $segment['total_spending'] = round((float)$segment['total_spending'], 2);
            $segment['average_daily_burn'] = round($monthCount > 0 ? $segment['monthly_burn_sum'] / $monthCount : 0.0, 2);
            $segment['latest_daily_burn'] = round($latestBySegment[$key] ?? 0.0, 2);
            $segment['share'] = $totalSpending > 0 ? round($segment['total_spending'] / $totalSpending * 100, 1) : 0.0;
            unset($segment['monthly_burn_sum']);
        }
        unset($segment);
        usort($segments, function(array $left, array $right): int {
            return $right['average_daily_burn'] <=> $left['average_daily_burn'];
        });

        $daily = [];
        $rollingWindow = [];
        $rollingTotal = 0.0;
        $peak = ['date' => null, 'amount' => 0.0];
        foreach ($dailyActual as $date => $actual) {
            $actual = round($actual, 2);
            $rollingWindow[] = $actual;
            $rollingTotal += $actual;
            if (count($rollingWindow) > 14) {
                $rollingTotal -= array_shift($rollingWindow);
            }
            if ($actual > $peak['amount']) {
                $peak = ['date' => $date, 'amount' => $actual];
            }
            $daily[] = [
                'date' => $date,
                'label' => (new DateTimeImmutable($date))->format('j M'),
                'actual_spending' => $actual,
                'rolling_average' => round($rollingTotal / count($rollingWindow), 2),
            ];
        }

        $latestDailyBurn = is_array($latestMonth) ? (float)$latestMonth['daily_burn'] : 0.0;
        $averageDailyBurn = $monthCount > 0 ? $burnSum / $monthCount : 0.0;
        return [
            'period' => [
                'start' => $start,
                'end' => $end,
                'months' => $monthCount,
                'calendar_days' => count($daily),
            ],
            'metrics' => [
                'latest_daily_burn' => round($latestDailyBurn, 2),
                'average_daily_burn' => round($averageDailyBurn, 2),
                'monthly_equivalent' => round($averageDailyBurn * 30.4375, 2),
                'total_spending' => round($totalSpending, 2),
                'transaction_count' => $transactionCount,
                'peak_day' => $peak,
            ],
            'months' => array_values($months),
            'daily' => $daily,
            'segments' => array_values($segments),
            'method' => 'Each month’s observed expenses are divided by its calendar days. Transfers, income and IGNORE-tagged transactions are excluded.',
        ];
    }
}
?>
