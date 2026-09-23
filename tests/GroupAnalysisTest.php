<?php
// Isolated fixtures: all writes are rolled back before the rest of the suite.
require_once __DIR__ . '/../php_backend/models/GroupAnalysis.php';
$db->beginTransaction();
try {
    $holiday = TransactionGroup::create('Group analysis holiday');
    $other = TransactionGroup::create('Other group');
    $taxi = Tag::create('Group analysis taxi');
    $hotel = Tag::create('Group analysis hotel');
    $ignore = Tag::getIgnoreId();
    $insert = $db->prepare('INSERT INTO transactions (group_id, tag_id, date, amount, transfer_id) VALUES (?, ?, ?, ?, ?)');
    foreach ([
        [$holiday, $taxi, '2026-08-01', -20, null],
        [$holiday, $taxi, '2026-08-31', -30, null],
        [$holiday, $taxi, '2026-08-15', 5, null],
        [$holiday, $hotel, '2026-08-10', -200, null],
        [$holiday, null, '2026-08-11', -10, null],
        [$holiday, $taxi, '2026-08-12', -100, 123],
        [$holiday, $ignore, '2026-08-13', -500, null],
        [$holiday, $ignore, '2026-08-14', -100, 124],
        [$holiday, $hotel, '2026-07-31', -50, null],
        [$holiday, $hotel, '2026-09-01', -60, null],
        [$other, $taxi, '2026-08-10', -999, null],
    ] as $row) $insert->execute($row);
    $data = GroupAnalysis::getSnapshot($holiday, '2026-08-01', '2026-08-31');
    assertEqual(260.0, $data['summary']['spending'], 'Group analysis isolates group and inclusive date boundaries');
    assertEqual(5.0, $data['summary']['income'], 'Group analysis keeps refunds separate from gross spending');
    assertEqual(255.0, $data['summary']['net_cost'], 'Group analysis reports spending less receipts as net cost');
    assertEqual(5, $data['summary']['count'], 'Group analysis counts only eligible transactions');
    assertEqual(3, $data['summary']['excluded'], 'Group analysis counts overlapping exclusions once');
    assertEqual($hotel, $data['tags'][0]['id'], 'Group analysis sorts largest spending tags first');
    assertEqual(null, $data['tags'][2]['id'], 'Group analysis retains untagged spending');
    assertEqual(100.0, round(array_sum(array_column($data['tags'], 'share')), 2), 'Group spending shares reconcile');
    assertEqual(370.0, GroupAnalysis::getSnapshot($holiday)['summary']['spending'], 'Blank dates include deposits and later spending');
    assertEqual(0, GroupAnalysis::getSnapshot($holiday, '2027-01-01')['summary']['count'], 'Empty periods return a zero summary');
    assertEqual(0.0, GroupAnalysis::getSnapshot($holiday, '2026-08-15', '2026-08-15')['tags'][0]['share'], 'Income-only periods do not divide by zero');
    foreach ([['2026-02-30', ''], ['2026-09-01', '2026-08-01']] as [$start, $end]) {
        $rejected = false;
        try { GroupAnalysis::getSnapshot($holiday, $start, $end); } catch (InvalidArgumentException $e) { $rejected = true; }
        assertEqual(true, $rejected, 'Group analysis rejects invalid or reversed dates');
    }
    $rejected = false;
    try { GroupAnalysis::getSnapshot(-1); } catch (InvalidArgumentException $e) { $rejected = true; }
    assertEqual(true, $rejected, 'Group analysis rejects unknown groups');
    $rows = Transaction::search('', start: '2026-08-01', end: '2026-08-31', dimension: 'tag', dimensionId: $taxi, direction: 'spending', transferScope: 'exclude', groupId: $holiday);
    assertEqual(2, count($rows), 'Tag evidence combines the group, dates, direction and transfer scope');
    assertEqual(-50.0, array_sum(array_map(static fn($row) => (float)$row['amount'], $rows)), 'Tag evidence reconciles to displayed spending');
    $rows = Transaction::search('', start: '2026-08-01', end: '2026-08-31', dimension: 'tag', unclassified: true, transferScope: 'exclude', groupId: $holiday);
    assertEqual(1, count($rows), 'Untagged evidence remains limited to the selected group');
    $rows = Transaction::search('', start: '2026-08-01', end: '2026-08-31', ignoredScope: 'include', groupId: $holiday);
    assertEqual(8, count($rows), 'All group entries includes transfers and IGNORE without other groups');
    // Make SQLite LIKE case-sensitive to exercise the PostgreSQL search behaviour.
    $db->exec('PRAGMA case_sensitive_like = ON');
    $rows = Transaction::search('ANALYSIS HOLIDAY', start: '2026-08-01', end: '2026-08-31', transferScope: 'exclude');
    assertEqual(5, count($rows), 'Group name search matches partial names regardless of case');
    TransactionGroup::setActive($holiday, false);
    $rows = Transaction::search('group ANALYSIS holiday', start: '2026-08-01', end: '2026-08-31', transferScope: 'exclude');
    assertEqual(5, count($rows), 'Inactive group history remains searchable by name');
    $db->exec('PRAGMA case_sensitive_like = OFF');
} finally {
    $db->rollBack();
}
