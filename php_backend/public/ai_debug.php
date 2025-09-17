<?php
// Simple endpoint to expose whether AI debug mode is enabled.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/Setting.php';

header('Content-Type: application/json');

$debug = Setting::get('ai_debug') === '1';
echo json_encode(['debug' => $debug]);

// Self-check:
// Endpoint detected: Responses
?>

