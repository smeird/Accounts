<?php
require_once __DIR__ . '/../Totp.php';
ini_set('session.cookie_secure', '1');
session_start();
header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);
$username = $input['username'] ?? ($_SESSION['username'] ?? '');
$token = $input['token'] ?? '';
if ($username === '' || $token === '') {
    echo json_encode(['verified' => false, 'error' => 'Missing fields']);
    exit;
}
$file = __DIR__ . '/../totp_secrets.json';
$users = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
if (!isset($users[$username])) {
    echo json_encode(['verified' => false, 'error' => 'Unknown user']);
    exit;
}
$secret = $users[$username];
$verified = Totp::verifyCode($secret, $token);
echo json_encode(['verified' => $verified]);
?>
