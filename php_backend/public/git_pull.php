<?php
// Authenticated, allowlisted application update status and fast-forward action.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/Log.php';
require_once __DIR__ . '/../services/ApplicationUpdateService.php';

header('Content-Type: application/json; charset=utf-8');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$service = new ApplicationUpdateService(dirname(__DIR__, 2));

try {
    if ($method === 'GET') {
        echo json_encode($service->status(true), JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($method !== 'POST') {
        http_response_code(405);
        header('Allow: GET, POST');
        echo json_encode(['status' => 'error', 'message' => 'Use GET to check or POST to install an update.']);
        exit;
    }
    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload) || ($payload['action'] ?? '') !== 'update' || ($payload['confirm'] ?? '') !== 'INSTALL_UPDATE') {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Explicit application update confirmation is required.']);
        exit;
    }
    $result = $service->update();
    Log::write(
        $result['status'] === 'success'
            ? 'Application updated from ' . ($result['from'] ?? '?') . ' to ' . ($result['to'] ?? '?')
            : 'Application update refused or failed: ' . ($result['message'] ?? 'Unknown error'),
        $result['status'] === 'success' ? 'INFO' : 'ERROR'
    );
    if ($result['status'] !== 'success') http_response_code(409);
    echo json_encode($result, JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    Log::write('Application update failed: ' . $error->getMessage(), 'ERROR');
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'The application update could not be completed.', 'detail' => $error->getMessage()], JSON_UNESCAPED_SLASHES);
}
