<?php
// Restores categories, tags, groups, and transactions from an uploaded JSON backup.
require_once __DIR__ . '/../Database.php';

try {
    if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo 'No backup file uploaded.';
        exit;
    }

    $json = file_get_contents($_FILES['backup_file']['tmp_name']);
    $data = json_decode($json, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo 'Invalid backup data.';
        exit;
    }

    $db = Database::getConnection();
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    $db->exec('TRUNCATE TABLE category_tags');
    $db->exec('TRUNCATE TABLE transactions');
    $db->exec('TRUNCATE TABLE tags');
    $db->exec('TRUNCATE TABLE categories');
    $db->exec('TRUNCATE TABLE transaction_groups');
    $db->exec('SET FOREIGN_KEY_CHECKS=1');

    $stmtCat = $db->prepare('INSERT INTO categories (id, name) VALUES (:id, :name)');
    foreach ($data['categories'] as $row) {
        $stmtCat->execute(['id' => $row['id'], 'name' => $row['name']]);
    }

    $stmtTag = $db->prepare('INSERT INTO tags (id, name, keyword) VALUES (:id, :name, :keyword)');
    foreach ($data['tags'] as $row) {
        $stmtTag->execute(['id' => $row['id'], 'name' => $row['name'], 'keyword' => $row['keyword']]);
    }

    $stmtGrp = $db->prepare('INSERT INTO transaction_groups (id, name) VALUES (:id, :name)');
    foreach ($data['groups'] as $row) {
        $stmtGrp->execute(['id' => $row['id'], 'name' => $row['name']]);
    }

    $stmtTx = $db->prepare('INSERT INTO transactions (id, account_id, date, amount, description, memo, category_id, tag_id, group_id, transfer_id, ofx_id) VALUES (:id, :account_id, :date, :amount, :description, :memo, :category_id, :tag_id, :group_id, :transfer_id, :ofx_id)');
    foreach ($data['transactions'] as $row) {
        $stmtTx->execute([
            'id' => $row['id'],
            'account_id' => $row['account_id'],
            'date' => $row['date'],
            'amount' => $row['amount'],
            'description' => $row['description'],
            'memo' => $row['memo'],
            'category_id' => $row['category_id'],
            'tag_id' => $row['tag_id'],
            'group_id' => $row['group_id'],
            'transfer_id' => $row['transfer_id'],
            'ofx_id' => $row['ofx_id']
        ]);
    }

    $stmtCT = $db->prepare('INSERT INTO category_tags (category_id, tag_id) VALUES (:category_id, :tag_id)');
    foreach ($data['category_tags'] as $row) {
        $stmtCT->execute(['category_id' => $row['category_id'], 'tag_id' => $row['tag_id']]);
    }

    echo 'Restore complete.';
} catch (Exception $e) {
    http_response_code(500);
    echo 'Error: ' . $e->getMessage();
}
