<?php

// API endpoint to mark transaction pairs as transfers.

require_once __DIR__ . '/../auth.php';
require_api_auth();
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
    $rejected = 0;
    foreach ($pairs as $p) {
        if (!is_array($p) || count($p) !== 2 || !Transaction::linkTransfer((int)$p[0], (int)$p[1])) {
            $rejected++;
            continue;
        }
        $updated++;
    }

    if ($rejected > 0) {
        http_response_code(400);
        echo json_encode([
            'error' => 'One or more transfer pairs could not be linked. Ensure each pair is in different accounts, has opposite amounts, and is not linked to another transfer.',
            'updated' => $updated,
            'rejected' => $rejected
        ]);
        exit;
    }

    echo json_encode(['status' => 'ok', 'updated' => $updated, 'rejected' => $rejected]);
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Mark transfer error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
}
?>
