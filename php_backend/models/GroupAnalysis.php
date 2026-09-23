<?php
require_once __DIR__ . '/TransactionGroup.php';
require_once __DIR__ . '/Tag.php';

class GroupAnalysis {
    public static function getSnapshot(int $groupId, string $start = '', string $end = ''): array {
        foreach ([$start, $end] as $date) {
            if ($date === '') continue;
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException('Enter valid dates in YYYY-MM-DD format');
            }
        }
        if ($start !== '' && $end !== '' && $start > $end) {
            throw new InvalidArgumentException('The start date must be on or before the end date');
        }
        $group = TransactionGroup::find($groupId);
        if (!$group) throw new InvalidArgumentException('Choose an existing group');

        $params = ['group_id' => $groupId, 'ignore' => Tag::getActiveIdByName('IGNORE') ?? 0];
        $where = 't.group_id = :group_id';
        if ($start !== '') { $where .= ' AND t.date >= :start'; $params['start'] = $start; }
        if ($end !== '') { $where .= ' AND t.date <= :end'; $params['end'] = $end; }
        // Aggregate in SQL so the page never downloads an unbounded transaction ledger.
        $eligible = 't.transfer_id IS NULL AND (t.tag_id IS NULL OR t.tag_id != :ignore)';
        $stmt = Database::getConnection()->prepare("SELECT t.tag_id, tg.name,
            SUM(CASE WHEN $eligible THEN 1 ELSE 0 END) AS count,
            SUM(CASE WHEN $eligible AND t.amount < 0 THEN -t.amount ELSE 0 END) AS spending,
            SUM(CASE WHEN $eligible AND t.amount > 0 THEN t.amount ELSE 0 END) AS income,
            SUM(CASE WHEN $eligible THEN 0 ELSE 1 END) AS excluded,
            MIN(t.date) AS first_date, MAX(t.date) AS last_date
            FROM transactions t LEFT JOIN tags tg ON tg.id = t.tag_id
            WHERE $where GROUP BY t.tag_id, tg.name");
        $stmt->execute($params);
        $summary = ['spending' => 0.0, 'income' => 0.0, 'net_cost' => 0.0, 'count' => 0, 'excluded' => 0];
        $tags = [];
        $first = null; $last = null;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $first = $first === null ? $row['first_date'] : min($first, $row['first_date']);
            $last = $last === null ? $row['last_date'] : max($last, $row['last_date']);
            $summary['excluded'] += (int)$row['excluded'];
            if (!(int)$row['count']) continue;
            $tag = ['id' => $row['tag_id'] === null ? null : (int)$row['tag_id'],
                'name' => $row['name'] ?? 'Untagged', 'count' => (int)$row['count'],
                'spending' => round((float)$row['spending'], 2), 'income' => round((float)$row['income'], 2)];
            $tag['net_cost'] = round($tag['spending'] - $tag['income'], 2);
            foreach (['spending', 'income', 'count'] as $key) $summary[$key] += $tag[$key];
            $tags[] = $tag;
        }
        foreach (['spending', 'income'] as $key) $summary[$key] = round($summary[$key], 2);
        $summary['net_cost'] = round($summary['spending'] - $summary['income'], 2);
        foreach ($tags as &$tag) $tag['share'] = $summary['spending'] > 0 ? 100 * $tag['spending'] / $summary['spending'] : 0.0;
        unset($tag);
        usort($tags, static fn($a, $b) => ($b['spending'] <=> $a['spending']) ?: strcasecmp($a['name'], $b['name']));
        return ['group' => $group, 'start' => $start, 'end' => $end, 'first_date' => $first,
            'last_date' => $last, 'summary' => $summary, 'tags' => $tags];
    }
}
