<?php
// API endpoint providing transaction reports filtered by various criteria.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/Log.php';
require_once __DIR__ . '/../models/Transaction.php';

header('Content-Type: application/json');

$category = isset($_GET['category']) ? (int)$_GET['category'] : null;
$tag = isset($_GET['tag']) ? (int)$_GET['tag'] : null;
$group = isset($_GET['group']) ? (int)$_GET['group'] : null;
$text = isset($_GET['text']) ? trim($_GET['text']) : null;
$start = isset($_GET['start']) ? $_GET['start'] : null;
$end = isset($_GET['end']) ? $_GET['end'] : null;

echo json_encode(Transaction::filter($category, $tag, $group, $text, $start, $end));
?>
