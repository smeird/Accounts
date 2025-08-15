<?php
// API endpoint returning recent application log entries.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
$days = isset($_GET['days']) ? (int)$_GET['days'] : null;

if (isset($_GET['prune_days'])) {
    $prune = (int)$_GET['prune_days'];
    Log::prune($prune);
    Log::write('Pruned logs older than ' . $prune . ' days');
}

try {
    echo json_encode(Log::all($limit, $days));
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

