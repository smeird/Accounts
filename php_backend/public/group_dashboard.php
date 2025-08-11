<?php
// API endpoint returning group spending summaries.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/Log.php';
require_once __DIR__ . '/../models/Transaction.php';

header('Content-Type: application/json');

$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

try {
    $years = Transaction::getAvailableYears();
    $byYear = Transaction::getGroupTotalsByYear($year);
    $allYears = Transaction::getGroupTotalsByYears($years);
    echo json_encode([
        'year' => $year,
        'years' => $years,
        'byYear' => $byYear,
        'allYears' => $allYears
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
