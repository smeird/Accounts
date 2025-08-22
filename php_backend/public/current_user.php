<?php
// Returns the username of the currently logged-in user.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../Database.php';
ini_set('session.cookie_secure', '1');
session_start();

header('Content-Type: application/json');

if (isset($_SESSION['username'])) {
    $username = $_SESSION['username'];
    $db = Database::getConnection();
    $stmt = $db->prepare('SELECT 1 FROM totp_secrets WHERE username = :username');
    $stmt->execute(['username' => $username]);
    $has2fa = (bool)$stmt->fetchColumn();

    echo json_encode(['username' => $username, 'has2fa' => $has2fa]);
} else {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
}
