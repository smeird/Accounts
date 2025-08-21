<?php
require_once __DIR__ . '/../Totp.php';

ini_set('session.cookie_secure', '1');
session_start();
header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);
$username = $input['username'] ?? ($_SESSION['username'] ?? '');

if ($username === '') {
    echo json_encode(['disabled' => false, 'error' => 'Username required']);
    exit;
}
$file = __DIR__ . '/../totp_secrets.json';
$users = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
if (isset($users[$username])) {
    unset($users[$username]);
    file_put_contents($file, json_encode($users, JSON_PRETTY_PRINT));
    echo json_encode(['disabled' => true]);
} else {
    echo json_encode(['disabled' => false, 'error' => 'No 2FA to disable']);
}
?>
