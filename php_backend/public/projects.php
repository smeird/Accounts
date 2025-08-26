<?php
// REST API for managing home projects.
require_once __DIR__ . '/../models/Project.php';
require_once __DIR__ . '/../nocache.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ($method) {
    case 'GET':
        $archived = isset($_GET['archived']) ? (bool)$_GET['archived'] : false;
        echo json_encode(Project::all($archived));
        break;
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = Project::create($data);
        echo json_encode(['status' => 'ok', 'id' => $id]);
        break;
    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        if (isset($data['id'])) {
            $ok = Project::update((int)$data['id'], $data);
            echo json_encode(['status' => $ok ? 'ok' : 'error']);
        } else {
            echo json_encode(['status' => 'error', 'error' => 'Missing id']);
        }
        break;
    case 'PATCH':
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        if (isset($data['id']) && isset($data['archived'])) {
            $ok = Project::setArchived((int)$data['id'], (bool)$data['archived']);
            echo json_encode(['status' => $ok ? 'ok' : 'error']);
        } else {
            echo json_encode(['status' => 'error', 'error' => 'Missing id or archived']);
        }
        break;
    case 'DELETE':
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        if (isset($data['id'])) {
            $ok = Project::delete((int)$data['id']);
            echo json_encode(['status' => $ok ? 'ok' : 'error']);
        } else {
            echo json_encode(['status' => 'error', 'error' => 'Missing id']);
        }
        break;
    default:
        http_response_code(405);
        echo json_encode(['status' => 'error', 'error' => 'Method not allowed']);
}

?>

