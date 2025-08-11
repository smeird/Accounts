<?php
// API endpoint for managing categories and their tag assignments.
require_once __DIR__ . '/../models/Category.php';
require_once __DIR__ . '/../models/CategoryTag.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    try {
        echo json_encode(Category::allWithTags());
    } catch (Exception $e) {
        http_response_code(500);
        Log::write('Category error: ' . $e->getMessage(), 'ERROR');
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
                if ($name === '') {
                    http_response_code(400);
                    echo json_encode(['error' => 'Name required']);
                    return;
                }
                $id = Category::create($name);
                Log::write("Created category $name");
                echo json_encode(['id' => $id]);
                break;
            case 'add_tag':
                $categoryId = (int)($data['category_id'] ?? 0);
                $tagId = (int)($data['tag_id'] ?? 0);
                try {
                    CategoryTag::add($categoryId, $tagId);
                    Log::write("Added tag $tagId to category $categoryId");
                    echo json_encode(['status' => 'ok']);
                } catch (Exception $e) {
                    http_response_code(400);
                    Log::write('Add tag error: ' . $e->getMessage(), 'ERROR');
                    echo json_encode(['error' => $e->getMessage()]);
                }
                break;
            case 'remove_tag':
                $categoryId = (int)($data['category_id'] ?? 0);
                $tagId = (int)($data['tag_id'] ?? 0);
                CategoryTag::remove($categoryId, $tagId);
                Log::write("Removed tag $tagId from category $categoryId");
                echo json_encode(['status' => 'ok']);
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
        Category::delete($id);
        Log::write("Deleted category $id");
        echo json_encode(['status' => 'ok']);
    } elseif ($method === 'PUT') {
        $id = (int)($data['id'] ?? 0);
        $name = trim($data['name'] ?? '');
        if ($id <= 0 || $name === '') {
            http_response_code(400);
            echo json_encode(['error' => 'ID and name required']);
            return;
        }
        Category::update($id, $name);
        Log::write("Updated category $id");
        echo json_encode(['status' => 'ok']);
    } else {
        http_response_code(405);
    }
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Category error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
}

?>
