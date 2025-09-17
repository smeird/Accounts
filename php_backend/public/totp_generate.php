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

if ($sessionUsername === '') {
    Log::write('2FA generate without session username', 'ERROR');
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

if ($requestedUsername !== '' && $requestedUsername !== $sessionUsername) {
    Log::write("2FA generate username mismatch for '$sessionUsername'", 'WARN');
    echo json_encode(['error' => 'Username mismatch']);
    exit;
}

$username = $sessionUsername;

try {
    $db = Database::getConnection();
    $stmt = $db->prepare('SELECT secret FROM totp_secrets WHERE username = :username');
    $stmt->execute(['username' => $username]);
    $secret = $stmt->fetchColumn();
    if (!$secret) {
        $secret = Totp::generateSecret();
        $ins = $db->prepare('INSERT INTO totp_secrets (username, secret) VALUES (:username, :secret)');
        $ins->execute(['username' => $username, 'secret' => $secret]);
        Log::write("Generated 2FA secret for '$username'");
    }
} catch (Throwable $e) {
    Log::write("2FA generate DB error for '$username': " . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
    exit;
}

$otpauth = Totp::getOtpAuthUri($username, $secret);
echo json_encode(['secret' => $secret, 'otpauth' => $otpauth]);
