<?php
require_once __DIR__ . '/../models/Category.php';
require_once __DIR__ . '/../models/CategoryTag.php';

header('Content-Type: application/json');
$action = $_POST['action'] ?? null;

try {
    switch ($action) {
        case 'create':
            $name = trim($_POST['name'] ?? '');
            $id = Category::create($name);
            echo json_encode(['id' => $id]);
            break;
        case 'update':
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            Category::update($id, $name);
            echo json_encode(['status' => 'ok']);
            break;
        case 'add_tag':
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $tagId = (int)($_POST['tag_id'] ?? 0);
            CategoryTag::add($categoryId, $tagId);
            echo json_encode(['status' => 'ok']);
            break;
        case 'remove_tag':
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $tagId = (int)($_POST['tag_id'] ?? 0);
            CategoryTag::remove($categoryId, $tagId);
            echo json_encode(['status' => 'ok']);
            break;
        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
