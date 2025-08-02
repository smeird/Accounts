<?php
require_once __DIR__ . '/../models/Tag.php';
require_once __DIR__ . '/../models/Log.php';
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $name = $data['name'] ?? null;
    $keyword = $data['keyword'] ?? null;
    if (!$name) {
        http_response_code(400);
        echo json_encode(['error' => 'Name required']);
        exit;
    }
    try {
        $id = Tag::create($name, $keyword);
        Log::write("Created tag $name");
        echo json_encode(['id' => $id]);
    } catch (Exception $e) {
        http_response_code(500);
        Log::write('Tag error: ' . $e->getMessage(), 'ERROR');
        echo json_encode(['error' => 'Server error']);
    }
} elseif ($method === 'GET') {
    try {
        echo json_encode(Tag::all());
    } catch (Exception $e) {
        http_response_code(500);
        Log::write('Tag error: ' . $e->getMessage(), 'ERROR');
        echo json_encode([]);
    }
} elseif ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? null;
    $name = $data['name'] ?? null;
    $keyword = $data['keyword'] ?? null;
    if (!$id || !$name) {
        http_response_code(400);
        echo json_encode(['error' => 'ID and name required']);
        exit;
    }
    try {
        Tag::update((int)$id, $name, $keyword);
        Log::write("Updated tag $id");
        echo json_encode(['status' => 'ok']);
    } catch (Exception $e) {
        http_response_code(500);
        Log::write('Tag error: ' . $e->getMessage(), 'ERROR');
        echo json_encode(['error' => 'Server error']);
    }
} else {
    http_response_code(405);
}
?>
