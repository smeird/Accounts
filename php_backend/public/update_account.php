<?php
// API endpoint to update the name of an account.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/Account.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$accountId = $data['account_id'] ?? null;
$name = $data['name'] ?? null;
$operation = $data['operation'] ?? ($name !== null ? 'rename' : null);

if (!$accountId || !in_array($operation, ['rename', 'close', 'reopen'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

try {
    if ($operation === 'rename') {
        $name = trim((string)$name);
        if ($name === '') {
            throw new InvalidArgumentException('An account name is required');
        }
        Account::rename((int)$accountId, $name);
        echo json_encode(['status' => 'ok', 'name' => $name]);
    } else {
        $result = Account::setClosed((int)$accountId, $operation === 'close');
        echo json_encode(['status' => 'ok', 'account' => $result]);
    }
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Update account error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
}
?>
