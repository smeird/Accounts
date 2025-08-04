<?php
// API endpoint returning yearly totals for tags, categories, and groups.
require_once __DIR__ . '/../models/Log.php';
require_once __DIR__ . '/../models/Transaction.php';

header('Content-Type: application/json');

$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

try {
    $tags = Transaction::getTagTotalsByYear($year);
    $categories = Transaction::getCategoryTotalsByYear($year);
    $groups = Transaction::getGroupTotalsByYear($year);
    echo json_encode([
        'tags' => $tags,
        'categories' => $categories,
        'groups' => $groups
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
