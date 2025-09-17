<?php
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../Totp.php';
require_once __DIR__ . '/../models/Log.php';
require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$sessionUsername = $_SESSION['username'] ?? '';
$requestedUsername = trim($input['username'] ?? '');
$token = isset($input['token']) ? trim((string)$input['token']) : '';

if ($sessionUsername === '') {
    Log::write('2FA verify without session username', 'ERROR');
    echo json_encode(['verified' => false, 'error' => 'Authentication required']);
    exit;
}

if ($requestedUsername !== '' && $requestedUsername !== $sessionUsername) {
    Log::write("2FA verify username mismatch for '$sessionUsername'", 'WARN');
    echo json_encode(['verified' => false, 'error' => 'Username mismatch']);
    exit;
}

if ($token === '') {
    Log::write("2FA verify missing token for '$sessionUsername'", 'ERROR');
    echo json_encode(['verified' => false, 'error' => 'Missing fields']);
    exit;
}

$username = $sessionUsername;

try {
    $db = Database::getConnection();
    $stmt = $db->prepare('SELECT secret FROM totp_secrets WHERE username = :username');
    $stmt->execute(['username' => $username]);
    $secret = $stmt->fetchColumn();
} catch (Throwable $e) {
    Log::write("2FA verify DB error for '$username': " . $e->getMessage(), 'ERROR');
    echo json_encode(['verified' => false, 'error' => 'Server error']);
    exit;
}

if (!$secret) {
    Log::write("2FA verify unknown user '$username'", 'ERROR');
    echo json_encode(['verified' => false, 'error' => 'Unknown user']);
    exit;
}

$verified = Totp::verifyCode($secret, $token);
Log::write("2FA verification for '$username': " . ($verified ? 'success' : 'failure'), $verified ? 'INFO' : 'ERROR');
echo json_encode(['verified' => $verified]);
