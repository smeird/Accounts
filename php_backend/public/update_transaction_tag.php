<?php
// API endpoint to update a transaction's tag and apply auto-tagging.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Tag.php';
require_once __DIR__ . '/../models/CategoryTag.php';
require_once __DIR__ . '/../models/Segment.php';
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
    $sourceTransaction = Transaction::get((int)$transactionId);
    if (!$sourceTransaction || (int)$sourceTransaction['account_id'] !== (int)$accountId) {
        throw new InvalidArgumentException('Transaction does not belong to the supplied account');
    }
    if (!$tagId && $tagName) {
        $existing = Tag::getIdByName($tagName);
        if ($existing === null) {
            $tagId = Tag::create($tagName);
            Log::write("Created tag $tagName");
        } else {
            $tagId = $existing;
            Log::write("Reused existing tag $tagName via normalized lookup");
        }
    }

    Transaction::setTag((int)$transactionId, (int)$tagId);
    if ($sourceTransaction['transfer_id'] === null) {
        $learnedAlias = Tag::learnTransactionAlias((int)$tagId, (string)$sourceTransaction['description'], $sourceTransaction['memo'], 'manual', (float)$sourceTransaction['amount']);
        if (in_array($learnedAlias['status'], ['conflict', 'overlap'], true)) {
            Log::write('Tag alias conflict while updating transaction ' . $transactionId . ': ' . json_encode($learnedAlias), 'WARNING');
        }
    }

    $applied = Tag::applyToAllTransactions();
    $categorised = CategoryTag::applyToAllTransactions();
    $segmented = Segment::applyToTransactions();

    echo json_encode([
        'status' => 'ok',
        'tag_id' => (int)$tagId,
        'auto_tagged' => $applied,
        'auto_categorised' => $categorised,
        'auto_segmented' => $segmented,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Update transaction tag error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
}
?>
