<?php

// Restores users, accounts, settings, segments, categories, tags (including tag aliases), groups,
// transactions, budgets, and projects from an uploaded gzipped JSON backup.

require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../models/Log.php';

try {
    if (!isset($_FILES['backup_file'])) {
        http_response_code(400);
        $msg = 'No backup file uploaded.';
        Log::write($msg, 'ERROR');
        echo $msg;
        exit;
    }


    $errCode = $_FILES['backup_file']['error'];
    if ($errCode !== UPLOAD_ERR_OK) {
        $errMap = [
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive specified in the HTML form.',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.'
        ];
        $msg = $errMap[$errCode] ?? 'Unknown upload error.';
        Log::write($msg, 'ERROR');
        http_response_code(400);
        echo $msg;
        exit;
    }

    $tmp = $_FILES['backup_file']['tmp_name'];

    $raw = file_get_contents($tmp);
    if ($raw === false) {

        http_response_code(400);
        $msg = 'Unable to read uploaded backup file.';
        Log::write($msg, 'ERROR');
        echo $msg;
        exit;
    }


    // Locate gzip signature if warnings or other text prefixed the archive
    $pos = strpos($raw, "\x1f\x8b");
    if ($pos !== false) {
        $gzData = substr($raw, $pos);
        $json = gzdecode($gzData);

        if ($json === false) {
            http_response_code(400);
            $msg = 'Unable to decompress backup.';
            Log::write($msg, 'ERROR');
            echo $msg;
            exit;
        }
    } else {
        $json = $raw;
    }


    $data = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        http_response_code(400);
        $msg = 'Invalid backup data: ' . json_last_error_msg();
        Log::write($msg, 'ERROR');
        echo $msg;
        exit;
    }

    $db = Database::getConnection();
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    if (isset($data['category_tags'])) $db->exec('TRUNCATE TABLE category_tags');
    if (isset($data['transactions'])) $db->exec('TRUNCATE TABLE transactions');
    if (isset($data['tag_aliases'])) $db->exec('TRUNCATE TABLE tag_aliases');
    if (isset($data['tags'])) $db->exec('TRUNCATE TABLE tags');
    if (isset($data['categories'])) $db->exec('TRUNCATE TABLE categories');
    if (isset($data['segments'])) $db->exec('TRUNCATE TABLE segments');
    if (isset($data['groups'])) $db->exec('TRUNCATE TABLE transaction_groups');
    if (isset($data['projects'])) $db->exec('TRUNCATE TABLE projects');
    if (isset($data['budgets'])) $db->exec('TRUNCATE TABLE budgets');
    if (isset($data['settings'])) $db->exec('TRUNCATE TABLE settings');
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

    if (isset($data['settings'])) {
        $stmtSet = $db->prepare('INSERT INTO settings (name, value) VALUES (:name, :value)');
        foreach ($data['settings'] as $row) {
            $stmtSet->execute(['name' => $row['name'], 'value' => $row['value']]);
        }
    }

    // Import segments first so categories can reference them
    $segmentMap = [];
    if (isset($data['segments'])) {
        $stmtSeg = $db->prepare('INSERT INTO segments (name, description) VALUES (:name, :description)');
        foreach ($data['segments'] as $row) {
            $stmtSeg->execute(['name' => $row['name'], 'description' => $row['description'] ?? null]);
            $segmentMap[$row['id']] = (int)$db->lastInsertId();
        }
    }

    if (isset($data['categories'])) {
        $stmtCat = $db->prepare('INSERT INTO categories (id, segment_id, name, description) VALUES (:id, :segment_id, :name, :description)');
        foreach ($data['categories'] as $row) {
            $segmentId = null;
            if (isset($row['segment_id'])) {
                $oldSeg = $row['segment_id'];
                $segmentId = $segmentMap[$oldSeg] ?? null;
            }
            $stmtCat->execute([
                'id' => $row['id'],
                'segment_id' => $segmentId,
                'name' => $row['name'],
                'description' => $row['description'] ?? null
            ]);
        }
    }

    if (isset($data['tags'])) {
        $stmtTag = $db->prepare('INSERT INTO tags (id, name, keyword, description) VALUES (:id, :name, :keyword, :description)');
        foreach ($data['tags'] as $row) {
            $stmtTag->execute(['id' => $row['id'], 'name' => $row['name'], 'keyword' => $row['keyword'], 'description' => $row['description'] ?? null]);
        }
    }

    if (isset($data['tag_aliases'])) {
        $stmtAlias = $db->prepare('INSERT INTO tag_aliases (id, tag_id, alias, alias_normalized, match_type, active, created_at, updated_at) VALUES (:id, :tag_id, :alias, :alias_normalized, :match_type, :active, :created_at, :updated_at)');
        foreach ($data['tag_aliases'] as $row) {
            $alias = trim((string)($row['alias'] ?? ''));
            if ($alias === '') {
                continue;
            }

            $stmtAlias->execute([
                'id' => $row['id'],
                'tag_id' => $row['tag_id'],
                'alias' => $alias,
                'alias_normalized' => $row['alias_normalized'] ?? strtolower($alias),
                'match_type' => ($row['match_type'] ?? 'contains') === 'exact' ? 'exact' : 'contains',
                'active' => isset($row['active']) ? (int)$row['active'] : 1,
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ]);
        }
    }

    if (isset($data['groups'])) {
        $stmtGrp = $db->prepare('INSERT INTO transaction_groups (id, name, description, active) VALUES (:id, :name, :description, :active)');
        foreach ($data['groups'] as $row) {
            $stmtGrp->execute([
                'id' => $row['id'],
                'name' => $row['name'],
                'description' => $row['description'] ?? null,
                'active' => isset($row['active']) ? (int)$row['active'] : 1
            ]);
        }
    }

    if (isset($data['projects'])) {
        $stmtProj = $db->prepare('INSERT INTO projects (id, name, description, rationale, cost_low, cost_medium, cost_high, funding_source, recurring_cost, estimated_time, expected_lifespan, benefit_financial, benefit_quality, benefit_risk, benefit_sustainability, weight_financial, weight_quality, weight_risk, weight_sustainability, dependencies, risks, archived, group_id, created_at) VALUES (:id, :name, :description, :rationale, :cost_low, :cost_medium, :cost_high, :funding_source, :recurring_cost, :estimated_time, :expected_lifespan, :benefit_financial, :benefit_quality, :benefit_risk, :benefit_sustainability, :weight_financial, :weight_quality, :weight_risk, :weight_sustainability, :dependencies, :risks, :archived, :group_id, :created_at)');
        foreach ($data['projects'] as $row) {
            $stmtProj->execute([
                'id' => $row['id'],
                'name' => $row['name'],
                'description' => $row['description'] ?? null,
                'rationale' => $row['rationale'] ?? null,
                'cost_low' => $row['cost_low'] ?? null,
                'cost_medium' => $row['cost_medium'] ?? null,
                'cost_high' => $row['cost_high'] ?? null,
                'funding_source' => $row['funding_source'] ?? null,
                'recurring_cost' => $row['recurring_cost'] ?? null,
                'estimated_time' => $row['estimated_time'] ?? null,
                'expected_lifespan' => $row['expected_lifespan'] ?? null,
                'benefit_financial' => $row['benefit_financial'] ?? null,
                'benefit_quality' => $row['benefit_quality'] ?? null,
                'benefit_risk' => $row['benefit_risk'] ?? null,
                'benefit_sustainability' => $row['benefit_sustainability'] ?? null,
                'weight_financial' => $row['weight_financial'] ?? null,
                'weight_quality' => $row['weight_quality'] ?? null,
                'weight_risk' => $row['weight_risk'] ?? null,
                'weight_sustainability' => $row['weight_sustainability'] ?? null,
                'dependencies' => $row['dependencies'] ?? null,
                'risks' => $row['risks'] ?? null,
                'archived' => $row['archived'] ?? 0,
                'group_id' => $row['group_id'] ?? null,
                'created_at' => $row['created_at'] ?? null,
            ]);
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
        $stmtTx = $db->prepare('INSERT IGNORE INTO transactions (id, account_id, date, amount, description, memo, category_id, tag_id, group_id, transfer_id, ofx_id, bank_ofx_id) VALUES (:id, :account_id, :date, :amount, :description, :memo, :category_id, :tag_id, :group_id, :transfer_id, :ofx_id, :bank_ofx_id)');
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
