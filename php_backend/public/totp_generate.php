<?php
require_once __DIR__ . '/../Totp.php';
header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);
$username = $input['username'] ?? '';
if ($username === '') {
    echo json_encode(['error' => 'Username required']);
    exit;
}
$file = __DIR__ . '/../totp_secrets.json';
$users = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
$secret = $users[$username] ?? Totp::generateSecret();
$users[$username] = $secret;
file_put_contents($file, json_encode($users, JSON_PRETTY_PRINT));
$qr = Totp::getQrCodeUrl($username, $secret);
echo json_encode(['secret' => $secret, 'qr' => $qr]);
?>
