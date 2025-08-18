<?php
// Endpoint to manage OpenAI API token.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/Setting.php';
require_once __DIR__ . '/../models/Log.php';
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') {
    $token = Setting::get('openai_api_token');
    echo json_encode(['token' => $token]);
} elseif ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $token = $data['token'] ?? null;
    if (!$token) {
        http_response_code(400);
        echo json_encode(['error' => 'Token required']);
        exit;
    }
    Setting::set('openai_api_token', $token);
    Log::write('Updated OpenAI API token');
    echo json_encode(['status' => 'ok']);
} else {
    http_response_code(405);
}
?>
