<?php
// API endpoint returning the chart-ready financial position snapshot.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/GraphsDashboard.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

try {
    echo json_encode(GraphsDashboard::getSnapshot($year));
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Graphs dashboard error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Unable to load graph data']);
}
?>
