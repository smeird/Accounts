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
    Log::write('2FA disable missing username', 'ERROR');
    echo json_encode(['disabled' => false, 'error' => 'Username required']);
    exit;
}
try {
    $db = Database::getConnection();
    $stmt = $db->prepare('DELETE FROM totp_secrets WHERE username = :username');
    $stmt->execute(['username' => $username]);
    if ($stmt->rowCount() > 0) {
        Log::write("2FA disabled for '$username'");
        echo json_encode(['disabled' => true]);
    } else {
        Log::write("2FA disable failed for '$username': no 2FA", 'ERROR');
        echo json_encode(['disabled' => false, 'error' => 'No 2FA to disable']);
    }
} catch (Throwable $e) {
    Log::write("2FA disable DB error for '$username': " . $e->getMessage(), 'ERROR');
    echo json_encode(['disabled' => false, 'error' => 'Server error']);
}
?>
