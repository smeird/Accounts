<?php

// Restores users, accounts, categories, tags, groups, transactions, and budgets from an uploaded gzipped JSON backup.

require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../models/Log.php';

try {
    if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        $msg = 'No backup file uploaded.';
        Log::write($msg, 'ERROR');
        echo $msg;
        exit;
    }

    $raw = file_get_contents($_FILES['backup_file']['tmp_name']);
    if ($raw === false) {
        http_response_code(400);
        $msg = 'Unable to read uploaded backup file.';
        Log::write($msg, 'ERROR');
        echo $msg;
        exit;
    }

    // Try to decompress gzipped backups, fall back to plain JSON
    $json = gzdecode($raw);
    if ($json === false) {

        $json = $raw;
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        http_response_code(400);
        $msg = 'Invalid backup data.';
        Log::write($msg, 'ERROR');
        echo $msg;
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
    if (isset($data['accounts'])) $db->exec('TRUNCATE TABLE accounts');
    if (isset($data['users'])) $db->exec('TRUNCATE TABLE users');
    $db->exec('SET FOREIGN_KEY_CHECKS=1');

    if (isset($data['users'])) {
        $stmtUser = $db->prepare('INSERT INTO users (id, username, password) VALUES (:id, :username, :password)');
        foreach ($data['users'] as $row) {
            $stmtUser->execute(['id' => $row['id'], 'username' => $row['username'], 'password' => $row['password']]);
        }
    }

    if (isset($data['accounts'])) {
        $stmtAcct = $db->prepare('INSERT INTO accounts (id, name, sort_code, account_number, ledger_balance, ledger_balance_date) VALUES (:id, :name, :sort_code, :account_number, :ledger_balance, :ledger_balance_date)');
        foreach ($data['accounts'] as $row) {
            $stmtAcct->execute([
                'id' => $row['id'],
                'name' => $row['name'],
                'sort_code' => $row['sort_code'] ?? null,
                'account_number' => $row['account_number'] ?? null,
                'ledger_balance' => $row['ledger_balance'],
                'ledger_balance_date' => $row['ledger_balance_date'] ?? null
            ]);
        }
    }

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
        $stmtTx = $db->prepare('INSERT INTO transactions (id, account_id, date, amount, description, memo, category_id, tag_id, group_id, transfer_id, ofx_id, bank_ofx_id) VALUES (:id, :account_id, :date, :amount, :description, :memo, :category_id, :tag_id, :group_id, :transfer_id, :ofx_id, :bank_ofx_id)');
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
                'ofx_id' => $row['ofx_id'],
                'bank_ofx_id' => $row['bank_ofx_id'] ?? null
            ]);
        }
    }

    if (isset($data['category_tags'])) {
        $stmtCT = $db->prepare('INSERT INTO category_tags (category_id, tag_id) VALUES (:category_id, :tag_id)');
        foreach ($data['category_tags'] as $row) {
            $stmtCT->execute(['category_id' => $row['category_id'], 'tag_id' => $row['tag_id']]);
        }
    }
    Log::write('Restore completed for parts: ' . implode(',', array_keys($data)));
    echo 'Restore complete.';
} catch (Exception $e) {
    Log::write('Restore error: ' . $e->getMessage(), 'ERROR');
    http_response_code(500);
    $msg = 'Error: ' . $e->getMessage();
    Log::write($msg, 'ERROR');
    echo $msg;
}
