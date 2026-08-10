<?php
// API endpoint returning the annual financial overview.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/Log.php';
require_once __DIR__ . '/../models/YearlyDashboard.php';

header('Content-Type: application/json');

$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

try {
    echo json_encode(YearlyDashboard::getSnapshot($year));
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Yearly dashboard error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Unable to load the yearly dashboard']);
}
?>
