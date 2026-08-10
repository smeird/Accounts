<?php
// API endpoint returning the complete glanceable snapshot for the Instant dashboard.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/InstantDashboard.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

try {
    echo json_encode(InstantDashboard::getSnapshot());
} catch (Throwable $e) {
    http_response_code(500);
    Log::write('Instant dashboard error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Unable to build the Instant dashboard right now.']);
}

?>
