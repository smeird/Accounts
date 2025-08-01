<?php
require_once __DIR__ . '/../models/Category.php';
require_once __DIR__ . '/../models/CategoryTag.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');
$action = $_POST['action'] ?? null;

try {
    switch ($action) {
        case 'create':
            $name = trim($_POST['name'] ?? '');
            if ($name === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Name required']);
                return;
            }
            $id = Category::create($name);
            Log::write("Created category $name");
            echo json_encode(['id' => $id]);
            break;
        case 'update':
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            if ($id <= 0 || $name === '') {
                http_response_code(400);
                echo json_encode(['error' => 'ID and name required']);
                return;
            }
            Category::update($id, $name);
            Log::write("Updated category $id");
            echo json_encode(['status' => 'ok']);
            break;
        case 'add_tag':
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $tagId = (int)($_POST['tag_id'] ?? 0);
            CategoryTag::add($categoryId, $tagId);
            Log::write("Added tag $tagId to category $categoryId");
            echo json_encode(['status' => 'ok']);
            break;
        case 'remove_tag':
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $tagId = (int)($_POST['tag_id'] ?? 0);
            CategoryTag::remove($categoryId, $tagId);
            Log::write("Removed tag $tagId from category $categoryId");
            echo json_encode(['status' => 'ok']);
            break;
        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Category error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
}
?>
