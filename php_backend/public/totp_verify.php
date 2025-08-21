<?php
require_once __DIR__ . '/../Totp.php';
require_once __DIR__ . '/../models/Log.php';

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
$file = __DIR__ . '/../totp_secrets.json';
$users = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
if (!isset($users[$username])) {
    Log::write("2FA verify unknown user '$username'", 'ERROR');
    echo json_encode(['verified' => false, 'error' => 'Unknown user']);
    exit;
}
$secret = $users[$username];
$verified = Totp::verifyCode($secret, $token);
Log::write("2FA verification for '$username': " . ($verified ? 'success' : 'failure'), $verified ? 'INFO' : 'ERROR');
echo json_encode(['verified' => $verified]);
?>
