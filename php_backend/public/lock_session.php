<?php
// Explicit inactivity lock. auth.php enforces same-origin requests.
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Use POST to lock the session.']);
    exit;
}
// Require the fetch-only custom header as well as auth.php's Origin check.
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(403);
    echo json_encode(['error' => 'Same-origin application request required.']);
    exit;
}
// Another window may have just authenticated or performed real activity.
// Do not let a suspended page destroy that newer session on resumption.
$minutes = (int)(Setting::get('session_timeout_minutes') ?? 0);
$lastActivity = (int)($_SESSION['last_activity'] ?? 0);
if (isset($_SESSION['user_id']) && ($minutes <= 0 || (time() - $lastActivity) < $minutes * 60)) {
    echo json_encode(['locked' => false]);
    exit;
}
$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
    session_unset();
    session_destroy();
}
echo json_encode(['locked' => true]);
