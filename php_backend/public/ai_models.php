<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../models/Setting.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$apiKey = Setting::get('openai_api_token');
if (!$apiKey) {
    http_response_code(400);
    echo json_encode(['error' => 'OpenAI API token not configured']);
    exit;
}

$ch = curl_init('https://api.openai.com/v1/models');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_TIMEOUT => 20,
]);

$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($response === false || $status >= 400) {
    http_response_code(502);
    echo json_encode(['error' => $error ?: 'Failed to fetch models']);
    exit;
}

$payload = json_decode($response, true);
if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
    http_response_code(502);
    echo json_encode(['error' => 'Invalid model list response']);
    exit;
}

$models = [];
foreach ($payload['data'] as $item) {
    if (!isset($item['id']) || !is_string($item['id'])) {
        continue;
    }
    if (strpos($item['id'], 'gpt-') !== 0 && strpos($item['id'], 'o') !== 0) {
        continue;
    }
    $models[] = $item['id'];
}

$models = array_values(array_unique($models));
sort($models);

echo json_encode(['models' => $models]);
