<?php
// API endpoint for managing category budgets.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/Budget.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
    $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
    try {
        echo json_encode(Budget::getMonthly($month, $year));
    } catch (Exception $e) {
        http_response_code(500);
        Log::write('Budget fetch error: ' . $e->getMessage(), 'ERROR');
        echo json_encode([]);
    }
    return;
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $category = (int)($data['category_id'] ?? 0);
    $amount = isset($data['amount']) ? (float)$data['amount'] : null;
    $month = isset($data['month']) ? (int)$data['month'] : (int)date('n');
    $year = isset($data['year']) ? (int)$data['year'] : (int)date('Y');
    if ($category <= 0 || $amount === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid data']);
        return;
    }
    try {
        $res = Budget::set($category, $month, $year, $amount);
        Log::write("Set budget for category $category to $amount SQL: " . json_encode($res));
        echo json_encode(['status' => 'ok']);
    } catch (Exception $e) {
        http_response_code(500);
        $info = ($e instanceof PDOException && $e->errorInfo) ? json_encode($e->errorInfo) : '';
        Log::write('Budget save error: ' . $e->getMessage() . ($info ? ' SQL: ' . $info : ''), 'ERROR');
        echo json_encode(['error' => 'Server error']);
    }
    return;
}

if ($method === 'DELETE') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ID required']);
        return;
    }
    try {
        Budget::delete($id);
        Log::write("Deleted budget $id");
        echo json_encode(['status' => 'ok']);
    } catch (Exception $e) {
        http_response_code(500);
        Log::write('Budget delete error: ' . $e->getMessage(), 'ERROR');
        echo json_encode(['error' => 'Server error']);
    }
    return;
}

http_response_code(405);
?>
