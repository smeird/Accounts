<?php
require_once __DIR__ . '/../Totp.php';
require_once __DIR__ . '/../models/Log.php';
require_once __DIR__ . '/../Database.php';

ini_set('session.cookie_secure', '1');
session_start();
header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);
$username = $input['username'] ?? ($_SESSION['username'] ?? '');

if ($username === '') {
    Log::write('2FA generate missing username', 'ERROR');
    echo json_encode(['error' => 'Username required']);
    exit;
}
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

?>
