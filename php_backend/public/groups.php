<?php
// API endpoint for creating, listing, updating, and deleting transaction groups.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/TransactionGroup.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];


if ($method === 'GET') {
    try {
        echo json_encode(TransactionGroup::all());
    } catch (Exception $e) {
        http_response_code(500);
        Log::write('Group error: ' . $e->getMessage(), 'ERROR');
        echo json_encode([]);
    }
    exit;
}


$data = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    if ($method === 'POST') {
        $name = trim($data['name'] ?? '');
        if ($name === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Name required']);
            return;
        }
        $id = TransactionGroup::create($name);
        Log::write("Created group $name");
        echo json_encode(['id' => $id]);
    } elseif ($method === 'PUT') {
        $id = (int)($data['id'] ?? 0);
        $name = trim($data['name'] ?? '');
        if ($id <= 0 || $name === '') {
            http_response_code(400);
            echo json_encode(['error' => 'ID and name required']);
            return;
        }
        TransactionGroup::update($id, $name);
        Log::write("Updated group $id");
        echo json_encode(['status' => 'ok']);
    } elseif ($method === 'DELETE') {
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'ID required']);
            return;
        }
        TransactionGroup::delete($id);
        Log::write("Deleted group $id");
        echo json_encode(['status' => 'ok']);
    } else {
        http_response_code(405);
    }
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Group error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
}

?>
