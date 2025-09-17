<?php
// API endpoint to update a transaction's tag and apply auto-tagging.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Tag.php';
require_once __DIR__ . '/../models/CategoryTag.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$transactionId = $data['transaction_id'] ?? null;
$accountId = $data['account_id'] ?? null;
$tagId = $data['tag_id'] ?? null;
$tagName = $data['tag_name'] ?? null;

$description = $data['description'] ?? null;

if (!$transactionId || !$accountId || (!$tagId && !$tagName) || !$description) {

    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

try {
    if (!$tagId && $tagName) {
        $existing = Tag::getIdByName($tagName);
        if ($existing === null) {
            $tagId = Tag::create($tagName, $description);
            Log::write("Created tag $tagName");
        } else {
            $tagId = $existing;
            Tag::setKeyword((int)$tagId, $description);
        }
    } else {
        Tag::setKeyword((int)$tagId, $description);
    }

    Transaction::setTag((int)$transactionId, (int)$tagId);

    $applied = Tag::applyToAccountTransactions((int)$accountId);
    $categorised = CategoryTag::applyToAccountTransactions((int)$accountId);

    echo json_encode([
        'status' => 'ok',
        'tag_id' => (int)$tagId,
        'auto_tagged' => $applied,
        'auto_categorised' => $categorised,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Update transaction tag error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
}
?>
