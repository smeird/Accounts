<?php
// API endpoint providing transaction reports filtered by various criteria.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/Log.php';
require_once __DIR__ . '/../models/Transaction.php';

header('Content-Type: application/json');

function parseList(string $key) {
    if (!isset($_GET[$key]) || $_GET[$key] === '') {
        return null;
    }
    $vals = array_filter(array_map('intval', explode(',', $_GET[$key])));
    return count($vals) > 1 ? $vals : ($vals ? $vals[0] : null);
}

$category = parseList('category');
$tag = parseList('tag');
$group = parseList('group');
$segment = parseList('segment');
$text = isset($_GET['text']) ? trim($_GET['text']) : null;
$memo = isset($_GET['memo']) ? trim($_GET['memo']) : null;
$start = isset($_GET['start']) ? $_GET['start'] : null;
$end = isset($_GET['end']) ? $_GET['end'] : null;

echo json_encode(Transaction::filter($category, $tag, $group, $segment, $text, $memo, $start, $end));
?>
