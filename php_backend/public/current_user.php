<?php
// Returns the username of the currently logged-in user.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

if (isset($_SESSION['username']) && $_SESSION['username'] !== '') {
    $username = $_SESSION['username'];
    $db = Database::getConnection();
    $stmt = $db->prepare('SELECT 1 FROM totp_secrets WHERE username = :username');
    $stmt->execute(['username' => $username]);
    $has2fa = (bool)$stmt->fetchColumn();

    echo json_encode(['username' => $username, 'has2fa' => $has2fa]);
} else {
    http_response_code(500);
    Log::write('Authenticated session missing username', 'ERROR');
    echo json_encode(['error' => 'Username unavailable']);
}
