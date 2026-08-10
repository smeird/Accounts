<?php
// REST API for managing home projects.
require_once __DIR__ . '/../models/Project.php';
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/**
 * Parse API boolean inputs consistently across query strings and JSON payloads.
 */
function parseBooleanInput($value, bool $default = false): bool {
    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value)) {
        return $value !== 0;
    }

    if (is_string($value)) {
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed !== null) {
            return $parsed;
        }
    }

    return $default;
}

try {
    switch ($method) {
        case 'GET':
            $archived = isset($_GET['archived']) ? parseBooleanInput($_GET['archived']) : false;
            echo json_encode(Project::all($archived));
            break;
        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true) ?? [];
            if (trim((string)($data['name'] ?? '')) === '') {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'error' => 'Project name is required']);
                break;
            }
            if (isset($data['archived'])) {
                $data['archived'] = parseBooleanInput($data['archived']) ? 1 : 0;
            }
            $id = Project::create($data);
            http_response_code(201);
            echo json_encode(['status' => 'ok', 'id' => $id]);
            break;
        case 'PUT':
            $data = json_decode(file_get_contents('php://input'), true) ?? [];
            if (!isset($data['id'])) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'error' => 'Missing id']);
                break;
            }
            if (trim((string)($data['name'] ?? '')) === '') {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'error' => 'Project name is required']);
                break;
            }
            if (isset($data['archived'])) {
                $data['archived'] = parseBooleanInput($data['archived']) ? 1 : 0;
            }
            $ok = Project::update((int)$data['id'], $data);
            if (!$ok) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'error' => 'Project not found']);
                break;
            }
            echo json_encode(['status' => 'ok']);
            break;
        case 'PATCH':
            $data = json_decode(file_get_contents('php://input'), true) ?? [];
            if (isset($data['id']) && isset($data['archived'])) {
                $ok = Project::setArchived((int)$data['id'], parseBooleanInput($data['archived']));
                if (!$ok) {
                    http_response_code(404);
                    echo json_encode(['status' => 'error', 'error' => 'Project not found']);
                    break;
                }
                echo json_encode(['status' => 'ok']);
            } else {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'error' => 'Missing id or archived']);
            }
            break;
        case 'DELETE':
            $data = json_decode(file_get_contents('php://input'), true) ?? [];
            if (isset($data['id'])) {
                $ok = Project::delete((int)$data['id']);
                if (!$ok) {
                    http_response_code(404);
                    echo json_encode(['status' => 'error', 'error' => 'Project not found']);
                    break;
                }
                echo json_encode(['status' => 'ok']);
            } else {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'error' => 'Missing id']);
            }
            break;
        default:
            http_response_code(405);
            echo json_encode(['status' => 'error', 'error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Projects API error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['status' => 'error', 'error' => 'Server error']);
}

?>
