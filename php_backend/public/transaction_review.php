<?php
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../services/TransactionEvidence.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $input = json_decode(file_get_contents('php://input', false, null, 0, 10000), true, 32, JSON_THROW_ON_ERROR);
        $ids = $input['ids'] ?? [];
        if (!is_array($ids) || !$ids || count($ids) > 250) throw new InvalidArgumentException('Choose up to 250 transactions to review.');
        $rows = Transaction::search('', transactionIds:$ids, transferScope:'exclude', ignoredScope:'exclude');
        $eligible = array_values(array_filter($rows, static fn($row) => !$row['tag_id'] || !$row['category_id']));
        $token = TransactionEvidence::create(array_column($eligible, 'id'), 'review');
        echo json_encode(['token'=>$token,'count'=>count($eligible),'first_id'=>$eligible[0]['id'] ?? null]);
    } elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $page = TransactionEvidence::page((string)($_GET['token'] ?? ''), 1, 'review');
        $position = filter_var($_GET['position'] ?? 0, FILTER_VALIDATE_INT);
        if ($position === false || $position < 0 || $position >= count($page['ids'])) throw new InvalidArgumentException('Invalid review position.');
        $transaction = Transaction::get($page['ids'][$position]);
        if (!$transaction || $transaction['transfer_id'] !== null || (int)$transaction['tag_id'] === Tag::getIgnoreId()) throw new InvalidArgumentException('This transaction is no longer eligible for classification review. Return to results to start a fresh review.');
        echo json_encode(['id'=>$page['ids'][$position],'next_id'=>$page['ids'][$position+1] ?? null,'position'=>$position,'count'=>count($page['ids'])]);
    } else {
        http_response_code(405); header('Allow: GET, POST'); echo json_encode(['error'=>'Method not allowed']);
    }
} catch (InvalidArgumentException|JsonException $e) {
    http_response_code(400); echo json_encode(['error'=>$e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500); echo json_encode(['error'=>'The review could not be opened. Please return to the transaction list.']);
}
