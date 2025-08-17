<?php
// API endpoint returning monthly totals for tags, categories, groups and income/outgoings.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/Log.php';
require_once __DIR__ . '/../models/Transaction.php';

header('Content-Type: application/json');

$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : date('n');

try {
    $totals = Transaction::getMonthlyTotals($month, $year);
    $tags = Transaction::getTagTotalsByMonth($month, $year);
    $categories = Transaction::getCategoryTotalsByMonth($month, $year);
    $groups = Transaction::getGroupTotalsByMonth($month, $year);
    $segments = Transaction::getSegmentTotalsByMonth($month, $year);
    echo json_encode([
        'totals' => $totals,
        'tags' => $tags,
        'categories' => $categories,
        'groups' => $groups,
        'segments' => $segments
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

?>

