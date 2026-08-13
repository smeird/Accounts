<?php
// API endpoint to update tag, category, and group of a transaction.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Tag.php';
require_once __DIR__ . '/../models/CategoryTag.php';
require_once __DIR__ . '/../models/Segment.php';
require_once __DIR__ . '/../models/TransactionGroup.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
Log::write('update_transaction payload: ' . json_encode($data));
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
    $sourceTransaction = Transaction::get((int)$transactionId);
    if (!$sourceTransaction || (int)$sourceTransaction['account_id'] !== (int)$accountId) {
        throw new InvalidArgumentException('Transaction does not belong to the supplied account');
    }
    $tagChanged = false;
    $categoryChanged = false;

    if ($groupId !== null) {

        $newGroup = $groupId === '' ? null : (int)$groupId;
        Log::write('Attempting group update for transaction ' . $transactionId . ' to ' . ($newGroup === null ? 'NULL' : $newGroup));
        $saved = Transaction::setGroup((int)$transactionId, $newGroup);
        Log::write('setGroup result for transaction ' . $transactionId . ': ' . ($saved ? 'success' : 'failure'));
        if (!$saved) {
            Log::write('Failed to update group for transaction ' . $transactionId, 'ERROR');
            throw new Exception('Failed to update group');
        }

        $groupName = 'NULL';
        if ($newGroup !== null) {
            $group = TransactionGroup::find($newGroup);
            $groupName = $group ? $group['name'] : $newGroup;
        }
        Log::write('Updated group for transaction ' . $transactionId . ' to ' . $groupName);

    }
    if ($tagId !== null || $tagName) {
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
            $learnedAlias = Tag::learnTransactionAlias((int)$tagId, (string)$sourceTransaction['description'], $sourceTransaction['memo']);
            if ($learnedAlias['status'] === 'conflict') {
                Log::write('Tag alias conflict while updating transaction ' . $transactionId . ': ' . json_encode($learnedAlias), 'WARNING');
            }
        }
        $tagChanged = true;
    }

    if ($categoryId !== null) {
        $newCategory = $categoryId === '' ? null : (int)$categoryId;
        if ($tagId) {
            $current = CategoryTag::getCategoryId((int)$tagId);
            if ($current !== $newCategory) {
                if ($current !== null && $newCategory !== null) {
                    CategoryTag::move($current, $newCategory, (int)$tagId);
                } elseif ($current !== null && $newCategory === null) {
                    CategoryTag::remove($current, (int)$tagId);
                } elseif ($current === null && $newCategory !== null) {
                    CategoryTag::add($newCategory, (int)$tagId);
                }
            }
        }
        Transaction::setCategory((int)$transactionId, $newCategory);
        $categoryChanged = true;
    }

    $applied = $tagChanged ? Tag::applyToAllTransactions() : 0;
    $categorised = ($tagChanged || $categoryChanged) ? CategoryTag::applyToAllTransactions() : 0;
    $segmented = ($tagChanged || $categoryChanged) ? Segment::applyToTransactions() : 0;

    echo json_encode([
        'status' => 'ok',
        'tag_id' => $tagId ? (int)$tagId : null,
        'group_id' => $groupId === '' ? null : ($groupId !== null ? (int)$groupId : null),
        'auto_tagged' => $applied,
        'auto_categorised' => $categorised,
        'auto_segmented' => $segmented
    ]);
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Update transaction error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
}
?>
