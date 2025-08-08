<?php
// Exports categories, tags, groups, and transactions as JSON.
require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json');

try {
    $db = Database::getConnection();

    $getAll = function(string $sql) use ($db) {
        $stmt = $db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };

    $data = [
        'categories' => $getAll('SELECT id, name FROM categories ORDER BY id'),
        'tags' => $getAll('SELECT id, name, keyword FROM tags ORDER BY id'),
        'category_tags' => $getAll('SELECT category_id, tag_id FROM category_tags ORDER BY category_id, tag_id'),
        'groups' => $getAll('SELECT id, name FROM transaction_groups ORDER BY id'),
        'transactions' => $getAll('SELECT id, account_id, date, amount, description, memo, category_id, tag_id, group_id, transfer_id, ofx_id FROM transactions ORDER BY id')
    ];

    echo json_encode($data);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
