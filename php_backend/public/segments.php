<?php
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/Segment.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    switch ($method) {
        case 'GET':
            echo json_encode(Segment::allWithCategories());
            break;
        case 'POST':
            if (isset($input['action'])) {
                switch ($input['action']) {
                    case 'add_category':
                    case 'move_category':
                        Segment::assignCategory((int)$input['segment_id'], (int)$input['category_id']);
                        Log::write("Assigned category {$input['category_id']} to segment {$input['segment_id']}");
                        echo json_encode(['status' => 'ok']);
                        break;
                    case 'remove_category':
                        Segment::assignCategory(null, (int)$input['category_id']);
                        Log::write("Removed category {$input['category_id']} from segment");
                        echo json_encode(['status' => 'ok']);
                        break;
                    default:
                        http_response_code(400);
                        echo json_encode(['error' => 'Unknown action']);
                }
            } else {
                $name = trim($input['name'] ?? '');
                $description = $input['description'] ?? null;
                if ($name === '') {
                    http_response_code(400);
                    echo json_encode(['error' => 'Name required']);
                    break;
                }
                $id = Segment::create($name, $description);
                Log::write("Created segment $name");
                echo json_encode(['id' => $id]);
            }
            break;
        case 'PUT':
            $id = (int)$input['id'];
            $name = $input['name'] ?? '';
            $description = $input['description'] ?? null;
            Segment::update($id, $name, $description);
            Log::write("Updated segment $id");
            echo json_encode(['status' => 'ok']);
            break;
        case 'DELETE':
            $id = (int)$input['id'];
            Segment::delete($id);
            Log::write("Deleted segment $id");
            echo json_encode(['status' => 'ok']);
            break;
        default:
            http_response_code(405);
            echo json_encode([]);
    }
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Segment error: ' . $e->getMessage(), 'ERROR');
    echo json_encode([]);
}
