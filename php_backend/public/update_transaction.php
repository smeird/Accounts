<?php
// API endpoint to update tag, category, and group of a transaction.
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
$description = $data['description'] ?? null;
$tagId = $data['tag_id'] ?? null;
$tagName = $data['tag_name'] ?? null;
$categoryId = $data['category_id'] ?? null;
$groupId = $data['group_id'] ?? null;

if (!$transactionId || !$accountId || !$description) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

try {
    $tagChanged = false;
    $categoryChanged = false;

    if ($categoryId !== null) {
        Transaction::setCategory((int)$transactionId, $categoryId === '' ? null : (int)$categoryId);
        $categoryChanged = true;
    }
    if ($groupId !== null) {
        $newGroup = $groupId === '' ? null : (int)$groupId;
        $saved = Transaction::setGroup((int)$transactionId, $newGroup);
        if (!$saved) {
            Log::write('Failed to update group for transaction ' . $transactionId, 'ERROR');
            throw new Exception('Failed to update group');
        }
        Log::write('Updated group for transaction ' . $transactionId . ' to ' . ($newGroup === null ? 'NULL' : $newGroup));
    }
    if ($tagId !== null || $tagName) {
        if (!$tagId && $tagName) {
            $tagId = Tag::create($tagName, $description);
            Log::write("Created tag $tagName");
        } else {
            Tag::setKeyword((int)$tagId, $description);
        }
        Transaction::setTag((int)$transactionId, (int)$tagId);
        $tagChanged = true;
    }

    $applied = $tagChanged ? Tag::applyToAccountTransactions((int)$accountId) : 0;
    $categorised = ($tagChanged || $categoryChanged) ? CategoryTag::applyToAccountTransactions((int)$accountId) : 0;

    echo json_encode([
        'status' => 'ok',
        'tag_id' => $tagId ? (int)$tagId : null,
        'group_id' => $groupId === '' ? null : ($groupId !== null ? (int)$groupId : null),
        'auto_tagged' => $applied,
        'auto_categorised' => $categorised
    ]);
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Update transaction error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
}
?>
