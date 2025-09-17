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
    Log::write('2FA disable without session username', 'ERROR');
    echo json_encode(['disabled' => false, 'error' => 'Authentication required']);
    exit;
}

if ($requestedUsername !== '' && $requestedUsername !== $sessionUsername) {
    Log::write("2FA disable username mismatch for '$sessionUsername'", 'WARN');
    echo json_encode(['disabled' => false, 'error' => 'Username mismatch']);
    exit;
}

$username = $sessionUsername;

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
