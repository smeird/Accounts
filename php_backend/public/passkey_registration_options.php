<?php
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../WebAuthn.php';
require_once __DIR__ . '/../models/Passkey.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Setting.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('Use POST to register a passkey.');
    }
    $userId = (int)$_SESSION['user_id'];
    $user = User::findById($userId);
    if (!$user) throw new RuntimeException('The signed-in user could not be found.');
    $context = WebAuthn::requestContext();
    $challenge = WebAuthn::challenge();
    $userHandle = Passkey::userHandleForUser($userId);
    $_SESSION['passkey_registration'] = WebAuthn::challengeRecord($challenge, $context, [
        'user_id' => $userId,
        'user_handle' => $userHandle,
    ]);
    echo json_encode(['publicKey' => [
        'challenge' => $challenge,
        'rp' => ['id' => $context['rp_id'], 'name' => Setting::getBrand()['site_name']],
        'user' => ['id' => $userHandle, 'name' => $user['username'], 'displayName' => $user['username']],
        'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7]],
        'timeout' => 120000,
        'attestation' => 'none',
        'authenticatorSelection' => [
            'residentKey' => 'required',
            'requireResidentKey' => true,
            'userVerification' => 'required',
        ],
        'excludeCredentials' => Passkey::descriptorsForUser($userId),
    ]]);
} catch (Exception $e) {
    Log::write('Passkey options failed: ' . $e->getMessage(), 'ERROR');
    if (http_response_code() < 400) http_response_code(500);
    echo json_encode(['error' => 'Passkey storage is not ready. Run Database Health after deployment.']);
}
?>
