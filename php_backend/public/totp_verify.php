<?php
require_once __DIR__ . '/../Totp.php';
require_once __DIR__ . '/../models/Log.php';
require_once __DIR__ . '/../Database.php';


ini_set('session.cookie_secure', '1');
session_start();
header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);
$username = $input['username'] ?? ($_SESSION['username'] ?? '');

$token = isset($input['token']) ? (string)$input['token'] : '';
if ($username === '' || trim($token) === '') {
    Log::write("2FA verify missing fields for '$username'", 'ERROR');
    echo json_encode(['verified' => false, 'error' => 'Missing fields']);
    exit;
}

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
?>
