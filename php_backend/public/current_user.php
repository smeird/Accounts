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

    $hasPasskey = false;
    try {
        $stmt = $db->prepare('SELECT 1 FROM passkeys WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => (int)$_SESSION['user_id']]);
        $hasPasskey = (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        // Passkeys are optional until the current schema migration is applied.
    }

    echo json_encode(['username' => $username, 'has2fa' => $has2fa, 'hasPasskey' => $hasPasskey]);
} else {
    http_response_code(500);
    Log::write('Authenticated session missing username', 'ERROR');
    echo json_encode(['error' => 'Username unavailable']);
}
