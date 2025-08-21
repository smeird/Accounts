<?php
// Returns the username of the currently logged-in user.
require_once __DIR__ . '/../nocache.php';
ini_set('session.cookie_secure', '1');
session_start();

header('Content-Type: application/json');

if (isset($_SESSION['username'])) {
    echo json_encode(['username' => $_SESSION['username']]);
} else {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
}
