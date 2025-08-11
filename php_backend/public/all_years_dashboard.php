<?php
// API endpoint returning totals across all available years for tags, categories, and groups.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/Log.php';
require_once __DIR__ . '/../models/Transaction.php';

header('Content-Type: application/json');

try {
    $years = Transaction::getAvailableYears();
    $tags = Transaction::getTagTotalsByYears($years);
    $categories = Transaction::getCategoryTotalsByYears($years);
    $groups = Transaction::getGroupTotalsByYears($years);
    echo json_encode([
        'years' => $years,
        'tags' => $tags,
        'categories' => $categories,
        'groups' => $groups
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
