<?php
// API endpoint to undo previously marked transfers.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$ids = $data['ids'] ?? [];
if (!$ids) {
    http_response_code(400);
    echo json_encode(['error' => 'No transaction ids supplied']);
    exit;
}

try {
    $updated = 0;
    foreach ($ids as $id) {
        if (Transaction::unlinkTransferById((int)$id)) {
            $updated++;
        }
    }
    echo json_encode(['status' => 'ok', 'updated' => $updated]);
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Unmark transfer error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
}
?>
