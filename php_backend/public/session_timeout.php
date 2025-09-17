<?php
// Returns inactivity timeout in minutes for the current user.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/Setting.php';

header('Content-Type: application/json');
$minutes = (int) (Setting::get('session_timeout_minutes') ?? 0);
echo json_encode(['minutes' => $minutes]);
