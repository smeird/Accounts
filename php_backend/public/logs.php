<?php
// API endpoint returning recent application log entries.
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
try {
    echo json_encode(Log::all($limit));
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

