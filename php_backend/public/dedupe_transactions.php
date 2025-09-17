<?php
// Lists duplicate transactions and removes extras when requested.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../models/Log.php';
require_once __DIR__ . '/../models/Tag.php';

header('Content-Type: application/json');

try {
    $db = Database::getConnection();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $ignore = Tag::getIgnoreId();
        $sql = 'SELECT GROUP_CONCAT(t.id) AS ids, COUNT(*) AS count, a.name AS account, '
             . 't.date, t.amount, MIN(TRIM(t.description)) AS description '
             . 'FROM transactions t JOIN accounts a ON t.account_id = a.id '
             . 'GROUP BY t.account_id, t.date, t.amount, UPPER(TRIM(t.description)) '
             . 'HAVING COUNT(*) > 1 AND SUM(CASE WHEN t.tag_id = :ignore THEN 1 ELSE 0 END) < COUNT(*)';
        $stmt = $db->prepare($sql);
        $stmt->execute(['ignore' => $ignore]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['ids'] = array_map('intval', explode(',', $row['ids']));
            $row['count'] = (int)$row['count'];
            $row['amount'] = (float)$row['amount'];
        }
        echo json_encode($rows);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $ids = $data['ids'] ?? [];
        if (!is_array($ids) || count($ids) < 2) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid ids']);
            exit;
        }
        $keep = min($ids);
        $toDelete = array_values(array_diff($ids, [$keep]));
        if (!$toDelete) {
            echo json_encode(['status' => 'ok', 'deleted' => 0]);
            exit;
        }
        $placeholders = implode(',', array_fill(0, count($toDelete), '?'));
        $stmt = $db->prepare("DELETE FROM transactions WHERE id IN ($placeholders)");
        $stmt->execute($toDelete);
        Log::write('Deduped transactions, kept ' . $keep . ' removed ' . implode(',', $toDelete));
        echo json_encode(['status' => 'ok', 'deleted' => $stmt->rowCount()]);
        exit;
    }

    http_response_code(405);
} catch (Exception $e) {
    Log::write('Dedupe error: ' . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
