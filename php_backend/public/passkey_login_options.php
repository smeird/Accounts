<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../WebAuthn.php';

header('Content-Type: application/json');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('Use POST to sign in with a passkey.');
    }
    $context = WebAuthn::requestContext();
    $challenge = WebAuthn::challenge();
    $_SESSION['passkey_login'] = WebAuthn::challengeRecord($challenge, $context);
    echo json_encode(['publicKey' => [
        'challenge' => $challenge,
        'timeout' => 120000,
        'rpId' => $context['rp_id'],
        'userVerification' => 'required',
        'allowCredentials' => [],
    ]]);
} catch (Exception $e) {
    if (http_response_code() < 400) http_response_code(500);
    echo json_encode(['error' => 'Passkey sign-in is not available.']);
}
?>
