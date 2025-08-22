<?php
// Returns inactivity timeout in minutes for the current user.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/Setting.php';
ini_set('session.cookie_secure', '1');
session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}
$minutes = (int) (Setting::get('session_timeout_minutes') ?? 0);
echo json_encode(['minutes' => $minutes]);
