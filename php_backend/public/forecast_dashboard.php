<?php
// API endpoint returning the transaction-backed financial forecast.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/Log.php';
require_once __DIR__ . '/../models/ForecastDashboard.php';

header('Content-Type: application/json');

try {
    echo json_encode(ForecastDashboard::getSnapshot(), JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Forecast dashboard error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Unable to build the financial forecast']);
}
?>
