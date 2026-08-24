<?php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/XlsxWorkbookWriter.php';

/**
 * Builds a self-contained personal-finance workbook for a selected period.
 * Transfers and IGNORE-tagged records remain visible in the ledger but never
 * inflate income, spending or the pivot-style analysis.
 */
class FinancialWorkbookExportService {
    private $db;

    public function __construct($db = null) {
        if ($db !== null && !($db instanceof PDO)) {
            throw new InvalidArgumentException('A PDO database connection is required.');
        }
        $this->db = $db ?: Database::getConnection();
    }

    public static function validateRange(string $start, string $end): array {
        $startDate = self::parseDate($start, 'start');
        $endDate = self::parseDate($end, 'end');
        if ($startDate > $endDate) {
            throw new InvalidArgumentException('The start date must be on or before the end date.');
        }
        return [$startDate, $endDate];
    }

    public function build(string $start, string $end): array {
        list($startDate, $endDate) = self::validateRange($start, $end);
        $stmt = $this->db->prepare(
            'SELECT t.`id`, t.`date`, t.`amount`, t.`description`, t.`memo`, t.`transfer_id`, '
            . 'a.`name` AS account_name, c.`name` AS category_name, '
            . 'COALESCE(cs.`name`, ts.`name`) AS segment_name, tg.`name` AS tag_name, g.`name` AS group_name '
            . 'FROM `transactions` t '
            . 'LEFT JOIN `accounts` a ON a.`id` = t.`account_id` '
            . 'LEFT JOIN `categories` c ON c.`id` = t.`category_id` '
            . 'LEFT JOIN `segments` cs ON cs.`id` = c.`segment_id` '
            . 'LEFT JOIN `segments` ts ON ts.`id` = t.`segment_id` '
            . 'LEFT JOIN `tags` tg ON tg.`id` = t.`tag_id` '
            . 'LEFT JOIN `transaction_groups` g ON g.`id` = t.`group_id` '
            . 'WHERE t.`date` BETWEEN :start AND :end ORDER BY t.`date`, t.`id`'
        );
        $stmt->execute(['start' => $start, 'end' => $end]);

        $transactions = [];
        $monthly = [];
        $accounts = [];
        $segments = [];
        $categories = [];
        $tags = [];
        $income = 0.0;
        $spending = 0.0;
        $largestExpense = 0.0;
        $expenseCount = 0;
        $analysedCount = 0;
        $transferCount = 0;
        $transferMoved = 0.0;
        $ignoredCount = 0;
        $taggedSpending = 0.0;

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $amount = round((float)$row['amount'], 2);
            $isTransfer = $row['transfer_id'] !== null;
            $isIgnored = strtoupper(trim((string)($row['tag_name'] ?? ''))) === 'IGNORE';
            $included = !$isTransfer && !$isIgnored;
            $type = $isTransfer ? 'Transfer' : ($isIgnored ? 'Ignored' : ($amount > 0 ? 'Income' : ($amount < 0 ? 'Expense' : 'Zero movement')));
            $inflow = $included && $amount > 0 ? $amount : 0.0;
            $outflow = $included && $amount < 0 ? abs($amount) : 0.0;
            $monthKey = substr((string)$row['date'], 0, 7);
            $monthDate = DateTimeImmutable::createFromFormat('!Y-m-d', $monthKey . '-01');
            $monthLabel = $monthDate ? $monthDate->format('M Y') : $monthKey;
            $account = self::label($row['account_name'] ?? null, 'Unknown account');
            $segment = self::label($row['segment_name'] ?? null, 'Unassigned');
            $category = self::label($row['category_name'] ?? null, 'Unassigned');
            $tag = self::label($row['tag_name'] ?? null, 'Unassigned');
            $group = self::label($row['group_name'] ?? null, 'Unassigned');

            $transactions[] = [
                'id' => (int)$row['id'],
                'date' => (string)$row['date'],
                'account' => $account,
                'description' => (string)($row['description'] ?? ''),
                'memo' => (string)($row['memo'] ?? ''),
                'segment' => $segment,
                'category' => $category,
                'tag' => $tag,
                'group' => $group,
                'type' => $type,
                'amount' => $amount,
                'inflow' => $inflow,
                'outflow' => $outflow,
                'month' => $monthLabel,
                'included' => $included,
            ];

            if ($isTransfer) {
                $transferCount++;
                if ($amount < 0) $transferMoved += abs($amount);
            }
            if ($isIgnored) $ignoredCount++;
            if (!$included) continue;

            $analysedCount++;
            $income += $inflow;
            $spending += $outflow;
            if ($outflow > 0) {
                $expenseCount++;
                $largestExpense = max($largestExpense, $outflow);
                if ($tag !== 'Unassigned') $taggedSpending += $outflow;
            }

            self::addAggregate($monthly, $monthLabel, $inflow, $outflow);
            self::addAggregate($accounts, $account, $inflow, $outflow);
            if ($outflow > 0) {
                self::addSpendingAggregate($segments, $segment, $outflow);
                self::addSpendingAggregate($categories, $category, $outflow);
                self::addSpendingAggregate($tags, $tag, $outflow);
            }
        }

        uasort($accounts, [self::class, 'compareMovement']);
        uasort($segments, [self::class, 'compareSpending']);
        uasort($categories, [self::class, 'compareSpending']);
        uasort($tags, [self::class, 'compareSpending']);

        $days = (int)$startDate->diff($endDate)->format('%a') + 1;
        return [
            'period' => ['start' => $start, 'end' => $end, 'days' => $days],
            'transactions' => $transactions,
            'monthly' => $monthly,
            'accounts' => $accounts,
            'segments' => $segments,
            'categories' => $categories,
            'tags' => $tags,
            'metrics' => [
                'income' => round($income, 2),
                'spending' => round($spending, 2),
                'net' => round($income - $spending, 2),
                'total_transactions' => count($transactions),
                'analysed_transactions' => $analysedCount,
                'expense_count' => $expenseCount,
                'average_expense' => $expenseCount ? round($spending / $expenseCount, 2) : 0.0,
                'average_daily_spending' => $days ? round($spending / $days, 2) : 0.0,
                'largest_expense' => round($largestExpense, 2),
                'transfer_count' => $transferCount,
                'transfer_moved' => round($transferMoved, 2),
                'ignored_count' => $ignoredCount,
                'tag_coverage' => $spending > 0 ? round($taggedSpending / $spending, 6) : 0.0,
            ],
        ];
    }

    public function createWorkbook(string $start, string $end, string $path): array {
        $data = $this->build($start, $end);
        $writer = new XlsxWorkbookWriter([
            'title' => 'Financial snapshot ' . $start . ' to ' . $end,
            'creator' => 'Accounts',
        ]);
        list($summaryRows, $summaryOptions) = $this->summarySheet($data);
        list($analysisRows, $analysisOptions) = $this->analysisSheet($data);
        list($transactionRows, $transactionOptions) = $this->transactionsSheet($data);
        $writer->addSheet('Summary', $summaryRows, $summaryOptions);
        $writer->addSheet('Pivot Analysis', $analysisRows, $analysisOptions);
        $writer->addSheet('Transactions', $transactionRows, $transactionOptions);
        $writer->save($path);
        return $data;
    }

    private function summarySheet(array $data): array {
        $metrics = $data['metrics'];
        $transactionEnd = max(6, count($data['transactions']) + 5);
        $tx = "'Transactions'!";
        $rows = [];
        $rows[] = ['height' => 30, 'cells' => self::span('Financial snapshot', 'title', 8)];
        $rows[] = ['height' => 18, 'cells' => self::span('', 'title', 8)];
        $rows[] = ['height' => 22, 'cells' => self::span(self::periodLabel($data['period']) . '  •  Generated ' . gmdate('d M Y H:i') . ' UTC', 'subtitle', 8)];
        $rows[] = [];
        $rows[] = array_merge(self::span('INCOME', 'kpi_label', 2), self::span('SPENDING', 'kpi_label', 2), self::span('NET MOVEMENT', 'kpi_label', 2), self::span('INCLUDED MOVEMENTS', 'kpi_label', 2));
        $rows[] = array_merge(
            self::span(self::formula("SUM({$tx}\$K\$6:\$K\${$transactionEnd})", $metrics['income'], 'kpi_currency'), 'kpi_currency', 2),
            self::span(self::formula("SUM({$tx}\$L\$6:\$L\${$transactionEnd})", $metrics['spending'], 'kpi_currency'), 'kpi_currency', 2),
            self::span(self::formula('A6-C6', $metrics['net'], 'kpi_currency'), 'kpi_currency', 2),
            self::span(self::formula("COUNTIF({$tx}\$N\$6:\$N\${$transactionEnd},\"Yes\")", $metrics['analysed_transactions'], 'kpi_number'), 'kpi_number', 2)
        );
        $rows[] = array_merge(self::span('', 'kpi_currency', 2), self::span('', 'kpi_currency', 2), self::span('', 'kpi_currency', 2), self::span('', 'kpi_number', 2));
        $rows[] = [];
        $rows[] = self::span('At a glance', 'section', 8);
        $rows[] = [
            self::cell('Average daily spending', 'muted'), self::formula('C6/' . max(1, $data['period']['days']), $metrics['average_daily_spending'], 'currency'),
            self::cell('Average expense', 'muted'), self::formula("IFERROR(AVERAGEIF({$tx}\$L\$6:\$L\${$transactionEnd},\">0\"),0)", $metrics['average_expense'], 'currency'),
            self::cell('Largest expense', 'muted'), self::formula("MAX({$tx}\$L\$6:\$L\${$transactionEnd})", $metrics['largest_expense'], 'currency'),
            self::cell('Tagged spending', 'muted'), self::formula("IFERROR(SUMIFS({$tx}\$L\$6:\$L\${$transactionEnd},{$tx}\$G\$6:\$G\${$transactionEnd},\"<>Unassigned\",{$tx}\$I\$6:\$I\${$transactionEnd},\"Expense\")/C6,0)", $metrics['tag_coverage'], 'percent'),
        ];
        $topCategory = key($data['categories']);
        $topSegment = key($data['segments']);
        $rows[] = [
            self::cell('Internal transfers moved', 'muted'), self::formula("-SUMIFS({$tx}\$J\$6:\$J\${$transactionEnd},{$tx}\$I\$6:\$I\${$transactionEnd},\"Transfer\",{$tx}\$J\$6:\$J\${$transactionEnd},\"<0\")", $metrics['transfer_moved'], 'currency'),
            self::cell('Transfer entries', 'muted'), self::cell($metrics['transfer_count'], 'number'),
            self::cell('Top category', 'muted'), self::cell($topCategory ?: '—', 'text'),
            self::cell('Top segment', 'muted'), self::cell($topSegment ?: '—', 'text'),
        ];
        $rows[] = [];
        $rows[] = array_merge(self::span('Monthly cash flow', 'section', 4), self::span('Top spending categories', 'section', 4));
        $rows[] = [self::cell('Month', 'header'), self::cell('Income', 'header'), self::cell('Spending', 'header'), self::cell('Net', 'header'), self::cell('Category', 'header'), self::cell('Spending', 'header'), self::cell('Share', 'header'), self::cell('Transactions', 'header')];

        $monthly = array_values(array_map(function ($name, $values) { return ['name' => $name] + $values; }, array_keys($data['monthly']), array_values($data['monthly'])));
        $categories = array_slice(array_values(array_map(function ($name, $values) { return ['name' => $name] + $values; }, array_keys($data['categories']), array_values($data['categories']))), 0, 8);
        $detailCount = max(1, count($monthly), count($categories));
        $detailStart = 15;
        for ($index = 0; $index < $detailCount; $index++) {
            $rowNumber = $detailStart + $index;
            $monthlyItem = $monthly[$index] ?? null;
            $categoryItem = $categories[$index] ?? null;
            $row = [];
            if ($monthlyItem) {
                $row[] = self::cell($monthlyItem['name'], 'text');
                $row[] = self::formula("SUMIFS({$tx}\$K\$6:\$K\${$transactionEnd},{$tx}\$M\$6:\$M\${$transactionEnd},A{$rowNumber})", $monthlyItem['income'], 'currency');
                $row[] = self::formula("SUMIFS({$tx}\$L\$6:\$L\${$transactionEnd},{$tx}\$M\$6:\$M\${$transactionEnd},A{$rowNumber})", $monthlyItem['spending'], 'currency');
                $row[] = self::formula("B{$rowNumber}-C{$rowNumber}", $monthlyItem['net'], 'currency');
            } else {
                $row = array_fill(0, 4, self::cell('', 'text'));
            }
            if ($categoryItem) {
                $row[] = self::cell($categoryItem['name'], 'text');
                $row[] = self::formula("SUMIFS({$tx}\$L\$6:\$L\${$transactionEnd},{$tx}\$F\$6:\$F\${$transactionEnd},E{$rowNumber})", $categoryItem['spending'], 'currency');
                $share = $metrics['spending'] > 0 ? $categoryItem['spending'] / $metrics['spending'] : 0;
                $row[] = self::formula("IFERROR(F{$rowNumber}/\$C\$6,0)", $share, 'percent');
                $row[] = self::formula("COUNTIFS({$tx}\$F\$6:\$F\${$transactionEnd},E{$rowNumber},{$tx}\$I\$6:\$I\${$transactionEnd},\"Expense\")", $categoryItem['count'], 'number');
            } else {
                $row = array_merge($row, array_fill(0, 4, self::cell('', 'text')));
            }
            $rows[] = $row;
        }
        $rows[] = [];
        $rows[] = self::span('Workbook guide  •  Summary and analysis exclude internal transfers and IGNORE-tagged rows. The Transactions sheet retains them and labels why they were excluded.', 'muted', 8);

        $categoryEnd = $detailStart + max(0, count($categories) - 1);
        $options = [
            'columns' => [19, 15, 19, 15, 28, 16, 13, 15],
            'merges' => ['A1:H2', 'A3:H3', 'A5:B5', 'C5:D5', 'E5:F5', 'G5:H5', 'A6:B7', 'C6:D7', 'E6:F7', 'G6:H7', 'A9:H9', 'A12:D12', 'E12:H12', 'A' . count($rows) . ':H' . count($rows)],
            'freeze' => ['rows' => 3],
            'tab_color' => '6366F1',
            'landscape' => true,
            'data_bars' => count($categories) ? [['range' => 'F' . $detailStart . ':F' . $categoryEnd, 'color' => '818CF8']] : [],
        ];
        return [$rows, $options];
    }

    private function analysisSheet(array $data): array {
        $transactionEnd = max(6, count($data['transactions']) + 5);
        $tx = "'Transactions'!";
        $rows = [];
        $dataBars = [];
        $rows[] = ['height' => 30, 'cells' => self::span('Pivot analysis', 'title', 11)];
        $rows[] = self::span('', 'title', 11);
        $rows[] = self::span(self::periodLabel($data['period']) . '  •  Ready-made grouped views update from the transaction ledger when Excel recalculates.', 'subtitle', 11);
        $rows[] = [];
        $rows[] = array_merge(self::span('Monthly performance', 'section', 5), [self::cell('', 'default')], self::span('Account activity', 'section', 5));
        $rows[] = [self::cell('Month', 'header'), self::cell('Income', 'header'), self::cell('Spending', 'header'), self::cell('Net', 'header'), self::cell('Transactions', 'header'), self::cell('', 'default'), self::cell('Account', 'header'), self::cell('Income', 'header'), self::cell('Spending', 'header'), self::cell('Net', 'header'), self::cell('Transactions', 'header')];

        $monthly = array_values(array_map(function ($name, $values) { return ['name' => $name] + $values; }, array_keys($data['monthly']), array_values($data['monthly'])));
        $accounts = array_values(array_map(function ($name, $values) { return ['name' => $name] + $values; }, array_keys($data['accounts']), array_values($data['accounts'])));
        $firstBlockStart = 7;
        $firstBlockCount = max(1, count($monthly), count($accounts));
        for ($index = 0; $index < $firstBlockCount; $index++) {
            $rowNumber = $firstBlockStart + $index;
            $month = $monthly[$index] ?? null;
            $account = $accounts[$index] ?? null;
            $row = $month ? [
                self::cell($month['name'], 'text'),
                self::formula("SUMIFS({$tx}\$K\$6:\$K\${$transactionEnd},{$tx}\$M\$6:\$M\${$transactionEnd},A{$rowNumber})", $month['income'], 'currency'),
                self::formula("SUMIFS({$tx}\$L\$6:\$L\${$transactionEnd},{$tx}\$M\$6:\$M\${$transactionEnd},A{$rowNumber})", $month['spending'], 'currency'),
                self::formula("B{$rowNumber}-C{$rowNumber}", $month['net'], 'currency'),
                self::formula("COUNTIFS({$tx}\$M\$6:\$M\${$transactionEnd},A{$rowNumber},{$tx}\$N\$6:\$N\${$transactionEnd},\"Yes\")", $month['count'], 'number'),
            ] : array_fill(0, 5, self::cell('', 'text'));
            $row[] = self::cell('', 'default');
            $row = array_merge($row, $account ? [
                self::cell($account['name'], 'text'),
                self::formula("SUMIFS({$tx}\$K\$6:\$K\${$transactionEnd},{$tx}\$B\$6:\$B\${$transactionEnd},G{$rowNumber})", $account['income'], 'currency'),
                self::formula("SUMIFS({$tx}\$L\$6:\$L\${$transactionEnd},{$tx}\$B\$6:\$B\${$transactionEnd},G{$rowNumber})", $account['spending'], 'currency'),
                self::formula("H{$rowNumber}-I{$rowNumber}", $account['net'], 'currency'),
                self::formula("COUNTIFS({$tx}\$B\$6:\$B\${$transactionEnd},G{$rowNumber},{$tx}\$N\$6:\$N\${$transactionEnd},\"Yes\")", $account['count'], 'number'),
            ] : array_fill(0, 5, self::cell('', 'text')));
            $rows[] = $row;
        }

        $rows[] = [];
        $rows[] = array_merge(self::span('Spending by segment', 'section', 5), [self::cell('', 'default')], self::span('Spending by category', 'section', 5));
        $rows[] = [self::cell('Segment', 'header'), self::cell('Spending', 'header'), self::cell('Share', 'header'), self::cell('Transactions', 'header'), self::cell('Average', 'header'), self::cell('', 'default'), self::cell('Category', 'header'), self::cell('Spending', 'header'), self::cell('Share', 'header'), self::cell('Transactions', 'header'), self::cell('Average', 'header')];
        $classificationStart = count($rows) + 1;
        $segments = array_values(array_map(function ($name, $values) { return ['name' => $name] + $values; }, array_keys($data['segments']), array_values($data['segments'])));
        $categories = array_values(array_map(function ($name, $values) { return ['name' => $name] + $values; }, array_keys($data['categories']), array_values($data['categories'])));
        $classificationCount = max(1, count($segments), count($categories));
        for ($index = 0; $index < $classificationCount; $index++) {
            $rowNumber = $classificationStart + $index;
            $segment = $segments[$index] ?? null;
            $category = $categories[$index] ?? null;
            $row = $segment ? $this->dimensionCells($segment, 'E', 'A', $rowNumber, $transactionEnd, $data['metrics']['spending'], $tx) : array_fill(0, 5, self::cell('', 'text'));
            $row[] = self::cell('', 'default');
            $row = array_merge($row, $category ? $this->dimensionCells($category, 'F', 'G', $rowNumber, $transactionEnd, $data['metrics']['spending'], $tx) : array_fill(0, 5, self::cell('', 'text')));
            $rows[] = $row;
        }
        if (count($segments)) $dataBars[] = ['range' => 'B' . $classificationStart . ':B' . ($classificationStart + count($segments) - 1), 'color' => '6366F1'];
        if (count($categories)) $dataBars[] = ['range' => 'H' . $classificationStart . ':H' . ($classificationStart + count($categories) - 1), 'color' => '8B5CF6'];

        $rows[] = [];
        $rows[] = self::span('Spending by tag', 'section', 5);
        $rows[] = [self::cell('Tag', 'header'), self::cell('Spending', 'header'), self::cell('Share', 'header'), self::cell('Transactions', 'header'), self::cell('Average', 'header')];
        $tagStart = count($rows) + 1;
        $tags = array_values(array_map(function ($name, $values) { return ['name' => $name] + $values; }, array_keys($data['tags']), array_values($data['tags'])));
        foreach ($tags as $index => $tag) {
            $rows[] = $this->dimensionCells($tag, 'G', 'A', $tagStart + $index, $transactionEnd, $data['metrics']['spending'], $tx);
        }
        if (!$tags) $rows[] = [self::cell('No expense tags in this period', 'muted')];
        if (count($tags)) $dataBars[] = ['range' => 'B' . $tagStart . ':B' . ($tagStart + count($tags) - 1), 'color' => 'A855F7'];

        return [$rows, [
            'columns' => [25, 16, 13, 14, 15, 3, 28, 16, 13, 14, 15],
            'merges' => ['A1:K2', 'A3:K3', 'A5:E5', 'G5:K5', 'A' . ($classificationStart - 2) . ':E' . ($classificationStart - 2), 'G' . ($classificationStart - 2) . ':K' . ($classificationStart - 2), 'A' . ($tagStart - 2) . ':E' . ($tagStart - 2)],
            'freeze' => ['rows' => 3],
            'tab_color' => '8B5CF6',
            'landscape' => true,
            'data_bars' => $dataBars,
        ]];
    }

    private function transactionsSheet(array $data): array {
        $headers = ['Date', 'Account', 'Description', 'Memo', 'Segment', 'Category', 'Tag', 'Group', 'Type', 'Amount', 'Inflow', 'Outflow', 'Month', 'Included in analysis', 'Transaction ID'];
        $rows = [];
        $rows[] = ['height' => 30, 'cells' => self::span('Transaction ledger', 'title', count($headers))];
        $rows[] = self::span('', 'title', count($headers));
        $rows[] = self::span(self::periodLabel($data['period']) . '  •  ' . count($data['transactions']) . ' ledger rows', 'subtitle', count($headers));
        $rows[] = self::span('Filter and sort this Excel table freely. Transfer and ignored entries are retained but marked as excluded from the summary and pivot analysis.', 'muted', count($headers));
        $rows[] = array_map(function ($header) { return self::cell($header, 'header'); }, $headers);
        foreach ($data['transactions'] as $index => $transaction) {
            $rowNumber = $index + 6;
            $textStyle = $transaction['included'] ? 'text' : 'excluded';
            $rows[] = [
                ['value' => $transaction['date'], 'type' => 'date', 'style' => 'date'],
                self::cell($transaction['account'], $textStyle),
                self::cell($transaction['description'], $textStyle),
                self::cell($transaction['memo'], $textStyle),
                self::cell($transaction['segment'], $textStyle),
                self::cell($transaction['category'], $textStyle),
                self::cell($transaction['tag'], $textStyle),
                self::cell($transaction['group'], $textStyle),
                self::cell($transaction['type'], $textStyle),
                self::cell($transaction['amount'], 'currency'),
                self::formula("IF(AND(\$N{$rowNumber}=\"Yes\",\$J{$rowNumber}>0),\$J{$rowNumber},0)", $transaction['inflow'], 'currency'),
                self::formula("IF(AND(\$N{$rowNumber}=\"Yes\",\$J{$rowNumber}<0),-\$J{$rowNumber},0)", $transaction['outflow'], 'currency'),
                self::cell($transaction['month'], $textStyle),
                self::cell($transaction['included'] ? 'Yes' : 'No', $transaction['included'] ? 'boolean' : 'excluded'),
                self::cell($transaction['id'], 'number'),
            ];
        }
        $tableEnd = max(5, count($rows));
        return [$rows, [
            'columns' => [13, 23, 34, 34, 21, 24, 24, 20, 16, 15, 15, 15, 14, 20, 15],
            'merges' => ['A1:O2', 'A3:O3', 'A4:O4'],
            'freeze' => ['rows' => 5, 'columns' => 2],
            'tab_color' => '14B8A6',
            'landscape' => true,
            'table' => ['name' => 'TransactionsTable', 'range' => 'A5:O' . $tableEnd, 'headers' => $headers],
        ]];
    }

    private function dimensionCells(array $item, string $transactionColumn, string $labelColumn, int $rowNumber, int $transactionEnd, float $totalSpending, string $tx): array {
        $valueColumn = chr(ord($labelColumn) + 1);
        $countColumn = chr(ord($labelColumn) + 3);
        $spending = (float)$item['spending'];
        $count = (int)$item['count'];
        return [
            self::cell($item['name'], 'text'),
            self::formula("SUMIFS({$tx}\$L\$6:\$L\${$transactionEnd},{$tx}\${$transactionColumn}\$6:\${$transactionColumn}\${$transactionEnd},{$labelColumn}{$rowNumber})", $spending, 'currency'),
            self::formula("IFERROR({$valueColumn}{$rowNumber}/'Summary'!\$C\$6,0)", $totalSpending > 0 ? $spending / $totalSpending : 0, 'percent'),
            self::formula("COUNTIFS({$tx}\${$transactionColumn}\$6:\${$transactionColumn}\${$transactionEnd},{$labelColumn}{$rowNumber},{$tx}\$I\$6:\$I\${$transactionEnd},\"Expense\")", $count, 'number'),
            self::formula("IFERROR({$valueColumn}{$rowNumber}/{$countColumn}{$rowNumber},0)", $count ? $spending / $count : 0, 'currency'),
        ];
    }

    private static function parseDate(string $value, string $label): DateTimeImmutable {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Choose a valid ' . $label . ' date.');
        }
        return $date;
    }

    private static function label($value, string $fallback): string {
        $value = trim((string)$value);
        return $value === '' ? $fallback : $value;
    }

    private static function addAggregate(array &$items, string $name, float $income, float $spending): void {
        if (!isset($items[$name])) $items[$name] = ['income' => 0.0, 'spending' => 0.0, 'net' => 0.0, 'count' => 0];
        $items[$name]['income'] = round($items[$name]['income'] + $income, 2);
        $items[$name]['spending'] = round($items[$name]['spending'] + $spending, 2);
        $items[$name]['net'] = round($items[$name]['income'] - $items[$name]['spending'], 2);
        $items[$name]['count']++;
    }

    private static function addSpendingAggregate(array &$items, string $name, float $spending): void {
        if (!isset($items[$name])) $items[$name] = ['spending' => 0.0, 'count' => 0];
        $items[$name]['spending'] = round($items[$name]['spending'] + $spending, 2);
        $items[$name]['count']++;
    }

    private static function compareSpending(array $left, array $right): int {
        if ($left['spending'] == $right['spending']) return 0;
        return $left['spending'] < $right['spending'] ? 1 : -1;
    }

    private static function compareMovement(array $left, array $right): int {
        $leftValue = $left['income'] + $left['spending'];
        $rightValue = $right['income'] + $right['spending'];
        if ($leftValue == $rightValue) return 0;
        return $leftValue < $rightValue ? 1 : -1;
    }

    private static function cell($value, string $style = 'text'): array {
        return ['value' => $value, 'style' => $style];
    }

    private static function formula(string $formula, $value, string $style): array {
        return ['formula' => $formula, 'value' => $value, 'style' => $style];
    }

    private static function span($value, string $style, int $columns): array {
        $first = is_array($value) ? $value : self::cell($value, $style);
        $cells = [$first];
        while (count($cells) < $columns) $cells[] = self::cell('', $style);
        return $cells;
    }

    private static function periodLabel(array $period): string {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $period['start']);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $period['end']);
        return ($start ? $start->format('d M Y') : $period['start']) . ' — ' . ($end ? $end->format('d M Y') : $period['end']);
    }
}
