<?php
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/DailyBurn.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

$start = isset($_GET['start']) ? trim((string)$_GET['start']) : '';
$end = isset($_GET['end']) ? trim((string)$_GET['end']) : '';

try {
    if ($start === '' || $end === '') {
        throw new InvalidArgumentException('A start and end date are required');
    }
    echo json_encode(DailyBurn::getSnapshot($start, $end));
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Daily Burn error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Unable to load daily burn history']);
}
?>
