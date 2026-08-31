<?php
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/Passkey.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');
$userId = (int)$_SESSION['user_id'];

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        echo json_encode(['passkeys' => Passkey::allForUser($userId)]);
        exit;
    }
    if ($method !== 'DELETE') {
        http_response_code(405);
        throw new RuntimeException('Unsupported passkey action.');
    }
    $payload = json_decode((string)file_get_contents('php://input'), true);
    $id = (int)($payload['id'] ?? 0);
    if ($id < 1 || !Passkey::deleteForUser($id, $userId)) {
        http_response_code(404);
        throw new RuntimeException('Passkey not found.');
    }
    Log::write("User '" . ($_SESSION['username'] ?? $userId) . "' removed passkey #$id", 'WARNING');
    echo json_encode(['deleted' => true]);
} catch (Exception $e) {
    if (http_response_code() < 400) http_response_code(500);
    $status = http_response_code();
    Log::write('Passkey management failed: ' . $e->getMessage(), 'ERROR');
    $message = $status === 404
        ? 'Passkey not found.'
        : ($status === 405 ? 'Unsupported passkey action.' : 'Passkey storage is not ready. Run Database Health after deployment.');
    echo json_encode(['error' => $message]);
}
?>
