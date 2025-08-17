<?php
// API endpoint returning totals across all available years for segments, tags, categories, and groups.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/Log.php';
require_once __DIR__ . '/../models/Transaction.php';

header('Content-Type: application/json');

try {
    $years = Transaction::getAvailableYears();
    $segments = Transaction::getSegmentTotalsByYears($years);
    $tags = Transaction::getTagTotalsByYears($years);
    $categories = Transaction::getCategoryTotalsByYears($years);
    $groups = Transaction::getGroupTotalsByYears($years);
    $segments = Transaction::getSegmentTotalsByYears($years);
    echo json_encode([
        'years' => $years,
        'segments' => $segments,
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
