<?php
require_once __DIR__ . '/../TagTaxonomyPattern.php';

/**
 * Turn raw transactions into active monthly payment patterns.
 *
 * Recurrence is deliberately based on a stable merchant identity and a
 * tolerant monthly cadence. Bank references and collection dates can change
 * without splitting one bill into several rows.
 */
class RecurringPatternDetector {
    const ACTIVE_WINDOW_DAYS = 50;
    const TIMING_TOLERANCE_DAYS = 10;

    /**
     * @param array<int,array<string,mixed>> $transactions
     * @param DateTimeImmutable|null $asOf
     * @return array<int,array<string,mixed>>
     */
    public static function analyse(array $transactions, $asOf = null): array {
        $asOf = ($asOf ?: new DateTimeImmutable('today'))->setTime(0, 0, 0);
        $windowStart = $asOf->modify('-12 months');
        $groups = [];

        foreach ($transactions as $transaction) {
            $date = self::dateValue($transaction['date'] ?? null);
            if ($date === null || $date < $windowStart || $date > $asOf) {
                continue;
            }

            $amount = (float)($transaction['amount'] ?? 0);
            if (abs($amount) < 0.00001) {
                continue;
            }

            $description = trim((string)($transaction['description'] ?? ''));
            $memo = isset($transaction['memo']) ? (string)$transaction['memo'] : null;
            $identity = TagTaxonomyPattern::fromTransaction($description, $memo, $amount);
            if ($identity['alias_normalized'] === 'unclassified transaction') {
                continue;
            }
            $key = $identity['direction'] . '|' . $identity['alias_normalized'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'alias' => $identity['alias'],
                    'pattern_key' => $identity['signature'],
                    'entries' => [],
                    'descriptions' => [],
                ];
            }
            $groups[$key]['entries'][] = [
                'id' => (int)($transaction['id'] ?? 0),
                'date' => $date,
                'amount' => $amount,
                'description' => $description,
            ];
            if ($description !== '') {
                $groups[$key]['descriptions'][$description] = true;
            }
        }

        $patterns = [];
        foreach ($groups as $group) {
            usort($group['entries'], function ($left, $right) {
                $dateComparison = $left['date'] <=> $right['date'];
                return $dateComparison !== 0 ? $dateComparison : $left['id'] <=> $right['id'];
            });

            $cadence = self::monthlyCadence($group['entries'], $asOf);
            if ($cadence === null) {
                continue;
            }

            $amounts = array_column($group['entries'], 'amount');
            $latest = $group['entries'][count($group['entries']) - 1];
            $total = array_sum($amounts);
            $occurrences = count($group['entries']);
            $patterns[] = [
                'description' => self::displayName($group['alias']),
                'search_term' => $group['alias'],
                'descriptions' => array_keys($group['descriptions']),
                'pattern_key' => $group['pattern_key'],
                'frequency' => 'monthly',
                'schedule' => self::monthlySchedule($group['entries'], $cadence['day']),
                'day' => $cadence['day'],
                'occurrences' => $occurrences,
                'months_observed' => $cadence['months_observed'],
                'total' => abs((float)$total),
                'average' => abs((float)$total / $occurrences),
                'last_amount' => abs((float)$latest['amount']),
                'last_date' => $latest['date']->format('Y-m-d'),
                'confidence' => $cadence['confidence'],
            ];
        }

        usort($patterns, function ($left, $right) {
            return strcasecmp($left['description'], $right['description']);
        });
        return $patterns;
    }

    /**
     * Require a reasonably complete, once-a-month sequence. The collection day
     * is compared on a circular 31-day scale so month-end and early-month bank
     * processing remain close to one another.
     *
     * @param array<int,array<string,mixed>> $entries
     * @return array<string,mixed>|null
     */
    private static function monthlyCadence(array $entries, DateTimeImmutable $asOf) {
        $uniqueDates = [];
        $monthCounts = [];
        foreach ($entries as $entry) {
            $dateKey = $entry['date']->format('Y-m-d');
            $uniqueDates[$dateKey] = $entry['date'];
            $monthKey = $entry['date']->format('Y-m');
            $monthCounts[$monthKey] = ($monthCounts[$monthKey] ?? 0) + 1;
        }
        $dates = array_values($uniqueDates);
        usort($dates, function ($left, $right) { return $left <=> $right; });

        $monthsObserved = count($monthCounts);
        if ($monthsObserved < 2 || count($dates) < 2) {
            return null;
        }

        $firstMonth = self::monthIndex($dates[0]);
        $lastMonth = self::monthIndex($dates[count($dates) - 1]);
        $monthSpan = $lastMonth - $firstMonth + 1;
        $coverage = $monthSpan > 0 ? $monthsObserved / $monthSpan : 0;
        if (($monthsObserved === 2 && $monthSpan !== 2)
            || ($monthsObserved > 2 && $coverage < 0.60)) {
            return null;
        }

        $singleMonths = 0;
        foreach ($monthCounts as $count) {
            if ($count === 1) $singleMonths++;
        }
        $singleMonthRatio = $singleMonths / $monthsObserved;
        if ($singleMonthRatio < 0.75) {
            return null;
        }

        $day = self::typicalDay($dates);
        $timingMatches = 0;
        foreach ($dates as $date) {
            if (self::circularDayDistance((int)$date->format('j'), $day) <= self::TIMING_TOLERANCE_DAYS) {
                $timingMatches++;
            }
        }
        $timingRatio = $timingMatches / count($dates);
        if ($timingRatio < 0.70) {
            return null;
        }

        $latest = $dates[count($dates) - 1];
        $ageDays = (int)$latest->diff($asOf)->format('%r%a');
        if ($ageDays < 0 || $ageDays > self::ACTIVE_WINDOW_DAYS) {
            return null;
        }

        return [
            'day' => $day,
            'months_observed' => $monthsObserved,
            'confidence' => round(($coverage + $singleMonthRatio + $timingRatio) / 3, 3),
        ];
    }

    /** @param array<int,array<string,mixed>> $entries */
    private static function monthlySchedule(array $entries, int $typicalDay): string {
        $dates = [];
        foreach ($entries as $entry) {
            $dates[$entry['date']->format('Y-m-d')] = $entry['date'];
        }
        $dates = array_values($dates);

        if (count($dates) >= 3) {
            $lastWeekday = [];
            $ordinalWeekday = [];
            $monthEndCount = 0;
            foreach ($dates as $date) {
                $weekday = $date->format('l');
                $daysRemaining = (int)$date->format('t') - (int)$date->format('j');
                if ($daysRemaining < 7) {
                    $lastWeekday[$weekday] = ($lastWeekday[$weekday] ?? 0) + 1;
                }
                if ($daysRemaining <= 3) $monthEndCount++;
                $ordinal = (int)ceil((int)$date->format('j') / 7);
                $key = $ordinal . '|' . $weekday;
                $ordinalWeekday[$key] = ($ordinalWeekday[$key] ?? 0) + 1;
            }

            arsort($lastWeekday);
            $lastName = key($lastWeekday);
            if ($lastName !== null && current($lastWeekday) / count($dates) >= 0.70) {
                return 'Monthly · last ' . $lastName;
            }

            arsort($ordinalWeekday);
            $ordinalKey = key($ordinalWeekday);
            if ($ordinalKey !== null && current($ordinalWeekday) / count($dates) >= 0.70) {
                list($ordinal, $weekday) = explode('|', $ordinalKey, 2);
                return 'Monthly · ' . self::ordinalWord((int)$ordinal) . ' ' . $weekday;
            }

            if ($monthEndCount / count($dates) >= 0.70) {
                return 'Monthly · near month end';
            }
        }

        return 'Monthly · around the ' . self::ordinal($typicalDay);
    }

    /** @param array<int,DateTimeImmutable> $dates */
    private static function typicalDay(array $dates): int {
        $bestDay = 1;
        $bestDistance = PHP_INT_MAX;
        for ($candidate = 1; $candidate <= 31; $candidate++) {
            $distance = 0;
            foreach ($dates as $date) {
                $distance += self::circularDayDistance((int)$date->format('j'), $candidate);
            }
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestDay = $candidate;
            }
        }
        return $bestDay;
    }

    private static function circularDayDistance(int $left, int $right): int {
        $distance = abs($left - $right);
        return min($distance, 31 - $distance);
    }

    private static function monthIndex(DateTimeImmutable $date): int {
        return ((int)$date->format('Y') * 12) + (int)$date->format('n');
    }

    private static function dateValue($value) {
        if ($value instanceof DateTimeImmutable) return $value->setTime(0, 0, 0);
        if ($value === null || trim((string)$value) === '') return null;
        try {
            return new DateTimeImmutable((string)$value);
        } catch (Exception $e) {
            return null;
        }
    }

    private static function displayName(string $alias): string {
        if (function_exists('mb_convert_case')) {
            return mb_convert_case($alias, MB_CASE_TITLE, 'UTF-8');
        }
        return ucwords($alias);
    }

    private static function ordinal(int $number): string {
        $remainder = $number % 100;
        if ($remainder >= 11 && $remainder <= 13) return $number . 'th';
        $suffixes = [1 => 'st', 2 => 'nd', 3 => 'rd'];
        return $number . ($suffixes[$number % 10] ?? 'th');
    }

    private static function ordinalWord(int $number): string {
        $words = [1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'fifth'];
        return $words[$number] ?? self::ordinal($number);
    }
}
?>
