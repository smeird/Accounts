<?php

// API endpoint to mark transaction pairs as transfers.

require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$pairs = $data['pairs'] ?? [];
if (!$pairs) {
    http_response_code(400);
    echo json_encode(['error' => 'No transfer pairs supplied']);

    exit;
}

try {

    $updated = 0;
    foreach ($pairs as $p) {
        if (is_array($p) && count($p) === 2) {
            if (Transaction::linkTransfer((int)$p[0], (int)$p[1])) {
                $updated++;
            }
        }
    }

    echo json_encode(['status' => 'ok', 'updated' => $updated]);
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Mark transfer error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
}
?>
