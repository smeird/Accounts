<?php
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/GroupAnalysis.php';
require_once __DIR__ . '/../models/Log.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Use GET to load group analysis']);
    exit;
}
try {
    $id = filter_var($_GET['group_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) throw new InvalidArgumentException('Choose a group');
    echo json_encode(GroupAnalysis::getSnapshot($id, trim((string)($_GET['start'] ?? '')), trim((string)($_GET['end'] ?? ''))));
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    Log::write('Group Analysis error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Unable to load group analysis']);
}
