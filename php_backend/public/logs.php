<?php
// API endpoint returning recent application log entries.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/Log.php';
require_once __DIR__ . '/../models/Setting.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'DELETE') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $prune = isset($data['days']) ? (int)$data['days'] : 0;
    if ($prune < 1 || $prune > 3650) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'error' => 'Days must be between 1 and 3650']);
        exit;
    }
    if (!Log::prune($prune)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'error' => 'Logs could not be pruned']);
        exit;
    }
    Log::write('Pruned logs older than ' . $prune . ' days');
    echo json_encode(['status' => 'ok']);
    exit;
}

if ($method !== 'GET') {
    http_response_code(405);
    header('Allow: GET, DELETE');
    echo json_encode(['status' => 'error', 'error' => 'Method not allowed']);
    exit;
}

$limit = isset($_GET['limit']) ? max(1, min(1000, (int)$_GET['limit'])) : 100;
$retention = Setting::get('log_retention_days');
$retentionDays = $retention !== null && (int)$retention > 0
    ? max(1, min(3650, (int)$retention))
    : null;
$days = isset($_GET['days']) ? max(1, min(3650, (int)$_GET['days'])) : $retentionDays;
if ($retentionDays !== null) {
    Log::prune($retentionDays);
}

try {
    echo json_encode(Log::all($limit, $days));
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
