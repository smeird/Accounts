<?php
require_once __DIR__ . '/../models/Tag.php';
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
    $id = Tag::create($name, $keyword);
    echo json_encode(['id' => $id]);
} elseif ($method === 'GET') {
    echo json_encode(Tag::all());
} else {
    http_response_code(405);
}
?>
