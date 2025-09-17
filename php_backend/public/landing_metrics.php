<?php
// Provides summary counts for the landing page hero stats.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/Stats.php';

header('Content-Type: application/json');

try {
    echo json_encode(Stats::getLandingMetrics());
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
