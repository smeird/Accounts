<?php
require_once __DIR__ . '/../models/Log.php';
require_once __DIR__ . '/../models/Transaction.php';

header('Content-Type: application/json');

$category = isset($_GET['category']) ? (int)$_GET['category'] : null;
$tag = isset($_GET['tag']) ? (int)$_GET['tag'] : null;
$group = isset($_GET['group']) ? (int)$_GET['group'] : null;

if ($category) {
    echo json_encode(Transaction::getByCategory($category));
} elseif ($tag) {
    echo json_encode(Transaction::getByTag($tag));
} elseif ($group) {
    echo json_encode(Transaction::getByGroup($group));
} else {
    echo json_encode([]);
}
?>
