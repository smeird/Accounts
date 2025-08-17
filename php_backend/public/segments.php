<?php
// API endpoint for managing segments and their category assignments.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/Segment.php';
require_once __DIR__ . '/../models/SegmentCategory.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    try {
        echo json_encode(Segment::allWithCategories());
    } catch (Exception $e) {
        http_response_code(500);
        Log::write('Segment error: ' . $e->getMessage(), 'ERROR');
        echo json_encode([]);
    }
    return;
}

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $data['action'] ?? null;

try {
    if ($method === 'POST') {
        switch ($action) {
            case null:
            case 'create':
                $name = trim($data['name'] ?? '');
                $description = $data['description'] ?? null;
                if ($name === '') {
                    http_response_code(400);
                    echo json_encode(['error' => 'Name required']);
                    return;
                }
                $id = Segment::create($name, $description);
                Log::write("Created segment $name");
                echo json_encode(['id' => $id]);
                break;
            case 'add_category':
                $segmentId = (int)($data['segment_id'] ?? 0);
                $categoryId = (int)($data['category_id'] ?? 0);
                try {
                    SegmentCategory::add($segmentId, $categoryId);
                    Log::write("Added category $categoryId to segment $segmentId");
                    echo json_encode(['status' => 'ok']);
                } catch (Exception $e) {
                    http_response_code(400);
                    Log::write('Add category error: ' . $e->getMessage(), 'ERROR');
                    echo json_encode(['error' => $e->getMessage()]);
                }
                break;
            case 'remove_category':
                $segmentId = (int)($data['segment_id'] ?? 0);
                $categoryId = (int)($data['category_id'] ?? 0);
                SegmentCategory::remove($segmentId, $categoryId);
                Log::write("Removed category $categoryId from segment $segmentId");
                echo json_encode(['status' => 'ok']);
                break;
            case 'move_category':
                $newSegmentId = (int)($data['segment_id'] ?? 0);
                $oldSegmentId = (int)($data['old_segment_id'] ?? 0);
                $categoryId = (int)($data['category_id'] ?? 0);
                try {
                    SegmentCategory::move($oldSegmentId, $newSegmentId, $categoryId);
                    Log::write("Moved category $categoryId from segment $oldSegmentId to $newSegmentId");
                    echo json_encode(['status' => 'ok']);
                } catch (Exception $e) {
                    http_response_code(400);
                    Log::write('Move category error: ' . $e->getMessage(), 'ERROR');
                    echo json_encode(['error' => $e->getMessage()]);
                }
                break;
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action']);
        }
    } elseif ($method === 'DELETE') {
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'ID required']);
            return;
        }
        Segment::delete($id);
        Log::write("Deleted segment $id");
        echo json_encode(['status' => 'ok']);
    } elseif ($method === 'PUT') {
        $id = (int)($data['id'] ?? 0);
        $name = trim($data['name'] ?? '');
        $description = $data['description'] ?? null;
        if ($id <= 0 || $name === '') {
            http_response_code(400);
            echo json_encode(['error' => 'ID and name required']);
            return;
        }
        Segment::update($id, $name, $description);
        Log::write("Updated segment $id");
        echo json_encode(['status' => 'ok']);
    } else {
        http_response_code(405);
    }
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Segment error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
}

?>
