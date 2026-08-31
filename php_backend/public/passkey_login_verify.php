<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../WebAuthn.php';
require_once __DIR__ . '/../models/Passkey.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('Use POST to sign in with a passkey.');
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 262144) throw new RuntimeException('Invalid passkey response.');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) throw new RuntimeException('Invalid passkey response.');
    $expected = $_SESSION['passkey_login'] ?? null;
    unset($_SESSION['passkey_login']);
    $expected = WebAuthn::assertChallengeRecord($expected);
    $encodedId = (string)($payload['rawId'] ?? $payload['id'] ?? '');
    $credentialId = WebAuthn::base64urlEncode(WebAuthn::base64urlDecode($encodedId));
    $credential = Passkey::findByCredentialId($credentialId);
    if (!$credential) throw new RuntimeException('Unknown passkey.');
    $verified = WebAuthn::verifyAuthentication($payload, $expected, $credential);
    if (!Passkey::recordUse((int)$credential['id'], (int)$credential['sign_count'], $verified['sign_count'], $verified['backed_up'])) {
        throw new RuntimeException('The passkey was used concurrently.');
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$credential['user_id'];
    $_SESSION['username'] = (string)$credential['username'];
    $_SESSION['last_activity'] = time();
    unset($_SESSION['pending_user_id'], $_SESSION['pending_username'], $_SESSION['passkey_registration']);
    Log::write("User '" . $credential['username'] . "' logged in with a passkey");
    echo json_encode(['authenticated' => true, 'redirect' => 'frontend/index.html']);
} catch (Exception $e) {
    Log::write('Passkey login failed: ' . $e->getMessage(), 'ERROR');
    if (http_response_code() < 400) http_response_code(401);
    echo json_encode(['error' => 'We could not verify that passkey. Please try again or use your password.']);
}
?>
