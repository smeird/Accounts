<?php
// Restores categories, tags, groups, transactions, and budgets from an uploaded JSON backup.
require_once __DIR__ . '/../nocache.php';
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
    if (isset($data['category_tags'])) $db->exec('TRUNCATE TABLE category_tags');
    if (isset($data['transactions'])) $db->exec('TRUNCATE TABLE transactions');
    if (isset($data['tags'])) $db->exec('TRUNCATE TABLE tags');
    if (isset($data['categories'])) $db->exec('TRUNCATE TABLE categories');
    if (isset($data['groups'])) $db->exec('TRUNCATE TABLE transaction_groups');
    if (isset($data['budgets'])) $db->exec('TRUNCATE TABLE budgets');
    $db->exec('SET FOREIGN_KEY_CHECKS=1');

    if (isset($data['categories'])) {
        $stmtCat = $db->prepare('INSERT INTO categories (id, name, description) VALUES (:id, :name, :description)');
        foreach ($data['categories'] as $row) {
            $stmtCat->execute(['id' => $row['id'], 'name' => $row['name'], 'description' => $row['description'] ?? null]);
        }
    }

    if (isset($data['tags'])) {
        $stmtTag = $db->prepare('INSERT INTO tags (id, name, keyword, description) VALUES (:id, :name, :keyword, :description)');
        foreach ($data['tags'] as $row) {
            $stmtTag->execute(['id' => $row['id'], 'name' => $row['name'], 'keyword' => $row['keyword'], 'description' => $row['description'] ?? null]);
        }
    }

    if (isset($data['groups'])) {
        $stmtGrp = $db->prepare('INSERT INTO transaction_groups (id, name, description) VALUES (:id, :name, :description)');
        foreach ($data['groups'] as $row) {
            $stmtGrp->execute(['id' => $row['id'], 'name' => $row['name'], 'description' => $row['description'] ?? null]);
        }
    }

    if (isset($data['budgets'])) {
        $stmtBud = $db->prepare('INSERT INTO budgets (category_id, month, year, amount) VALUES (:category_id, :month, :year, :amount)');
        foreach ($data['budgets'] as $row) {
            $stmtBud->execute([
                'category_id' => $row['category_id'],
                'month' => $row['month'],
                'year' => $row['year'],
                'amount' => $row['amount']
            ]);
        }
    }

    if (isset($data['transactions'])) {
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
    }

    if (isset($data['category_tags'])) {
        $stmtCT = $db->prepare('INSERT INTO category_tags (category_id, tag_id) VALUES (:category_id, :tag_id)');
        foreach ($data['category_tags'] as $row) {
            $stmtCT->execute(['category_id' => $row['category_id'], 'tag_id' => $row['tag_id']]);
        }
    }

    echo 'Restore complete.';
} catch (Exception $e) {
    http_response_code(500);
    echo 'Error: ' . $e->getMessage();
}
