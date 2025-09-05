<?php
// API endpoint for managing saved transaction reports.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/SavedReport.php';
require_once __DIR__ . '/../models/Log.php';
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    try {
        echo json_encode(SavedReport::all());
    } catch (Exception $e) {
        http_response_code(500);
        Log::write('Saved report fetch error: ' . $e->getMessage(), 'ERROR');
        echo json_encode([]);
    }
} elseif ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $name = $data['name'] ?? null;
    $description = $data['description'] ?? '';
    if (!$name) {
        http_response_code(400);
        echo json_encode(['error' => 'Name required']);
        exit;
    }
    $filters = $data;
    unset($filters['name'], $filters['description']);
    try {
        $id = SavedReport::create($name, $description, $filters);
        Log::write("Saved report $name");
        echo json_encode(['id' => $id]);
    } catch (Exception $e) {
        http_response_code(500);
        Log::write('Saved report save error: ' . $e->getMessage(), 'ERROR');
        echo json_encode(['error' => 'Server error']);
    }
} elseif ($method === 'DELETE') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? null;
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID required']);
        exit;
    }
    try {
        SavedReport::delete((int)$id);
        Log::write("Deleted saved report $id");
        echo json_encode(['status' => 'ok']);
    } catch (Exception $e) {
        http_response_code(500);
        Log::write('Saved report delete error: ' . $e->getMessage(), 'ERROR');
        echo json_encode(['error' => 'Server error']);
    }
} else {
    http_response_code(405);
}
?>
