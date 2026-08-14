<?php
// Authenticated schema-only database audit and repair endpoint.
require_once __DIR__ . '/../auth.php';
try {
    require_api_auth();
} catch (Throwable $authSchemaError) {
    // A missing settings table can prevent the normal session-timeout lookup.
    // Keep this recovery endpoint available only to an already authenticated
    // same-origin session so Database Health can restore the schema itself.
    if (PHP_SAPI !== 'cli' && empty($_SESSION['user_id'])) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Authentication required.']);
        exit;
    }
}
require_once __DIR__ . '/../services/SchemaHealthService.php';

header('Content-Type: application/json; charset=utf-8');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    $service = new SchemaHealthService();

    if ($method === 'GET') {
        echo json_encode($service->audit(), JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        header('Allow: GET, POST');
        echo json_encode(['status' => 'error', 'message' => 'Use GET to audit or POST to repair.']);
        exit;
    }

    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'A JSON repair request is required.']);
        exit;
    }
    if (($payload['action'] ?? '') !== 'repair' || ($payload['confirm'] ?? '') !== 'REPAIR_SCHEMA') {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Explicit schema repair confirmation is required.']);
        exit;
    }

    $issueIds = isset($payload['issue_ids']) && is_array($payload['issue_ids'])
        ? array_values(array_filter($payload['issue_ids'], 'is_string'))
        : [];
    if (!$issueIds) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Select at least one catalogue repair.']);
        exit;
    }

    $result = $service->repair($issueIds);
    echo json_encode($result, JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database Health could not complete the request.',
        'detail' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
}
