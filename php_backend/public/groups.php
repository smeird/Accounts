<?php
require_once __DIR__ . '/../models/TransactionGroup.php';
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
            $id = TransactionGroup::create($name);
            Log::write("Created group $name");
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
            TransactionGroup::update($id, $name);
            Log::write("Updated group $id");
            echo json_encode(['status' => 'ok']);
            break;
        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Group error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
}
?>
