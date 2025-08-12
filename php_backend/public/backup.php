<?php
// Exports selected data as JSON. Allows selecting categories, tags, groups,
// transactions, and budgets via the `parts` query parameter.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json');
$host = $_SERVER['HTTP_HOST'] ?? 'backup';
$host = preg_replace('/[^A-Za-z0-9_-]/', '_', $host);
$filename = $host . '-' . date('Y-m-d') . '.json';
header('Content-Disposition: attachment; filename="' . $filename . '"');

try {
    $db = Database::getConnection();

    $getAll = function(string $sql) use ($db) {
        $stmt = $db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };

    $allParts = ['categories','tags','groups','transactions','budgets'];
    $parts = isset($_GET['parts']) && $_GET['parts'] !== ''
        ? array_intersect($allParts, explode(',', $_GET['parts']))
        : $allParts;

    $data = [];
    if (in_array('categories', $parts)) {
        $data['categories'] = $getAll('SELECT id, name, description FROM categories ORDER BY id');
    }
    if (in_array('tags', $parts)) {
        $data['tags'] = $getAll('SELECT id, name, keyword, description FROM tags ORDER BY id');
    }
    if (in_array('categories', $parts) || in_array('tags', $parts)) {
        $data['category_tags'] = $getAll('SELECT category_id, tag_id FROM category_tags ORDER BY category_id, tag_id');
    }
    if (in_array('groups', $parts)) {
        $data['groups'] = $getAll('SELECT id, name, description FROM transaction_groups ORDER BY id');
    }
    if (in_array('transactions', $parts)) {
        $data['transactions'] = $getAll('SELECT id, account_id, date, amount, description, memo, category_id, tag_id, group_id, transfer_id, ofx_id FROM transactions ORDER BY id');
    }
    if (in_array('budgets', $parts)) {
        $data['budgets'] = $getAll('SELECT category_id, month, year, amount FROM budgets ORDER BY category_id, year, month');
    }

    echo json_encode($data);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
