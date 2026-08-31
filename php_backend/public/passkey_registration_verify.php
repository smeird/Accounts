<?php
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../WebAuthn.php';
require_once __DIR__ . '/../models/Passkey.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('Use POST to register a passkey.');
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 262144) throw new RuntimeException('Invalid passkey response.');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) throw new RuntimeException('Invalid passkey response.');
    $expected = $_SESSION['passkey_registration'] ?? null;
    unset($_SESSION['passkey_registration']);
    $expected = WebAuthn::assertChallengeRecord($expected);
    $userId = (int)$_SESSION['user_id'];
    if ((int)($expected['user_id'] ?? 0) !== $userId || empty($expected['user_handle'])) {
        throw new RuntimeException('The passkey registration session did not match.');
    }
    $verified = WebAuthn::verifyRegistration($payload, $expected);
    $label = trim((string)($payload['label'] ?? 'Passkey'));
    $id = Passkey::create($userId, $expected['user_handle'], $verified, $payload, $label);
    Log::write("User '" . ($_SESSION['username'] ?? $userId) . "' registered passkey #$id");
    echo json_encode(['registered' => true, 'id' => $id, 'message' => 'Passkey added.']);
} catch (Exception $e) {
    Log::write('Passkey registration failed: ' . $e->getMessage(), 'ERROR');
    if (http_response_code() < 400) http_response_code(400);
    echo json_encode(['error' => 'The passkey could not be registered. Please try again.']);
}
?>
