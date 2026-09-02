<?php

// Restores users, accounts, settings, segments, categories, tags (including tag aliases), groups,
// transactions, budgets, and projects from an uploaded gzipped JSON backup.

require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../models/Log.php';
require_once __DIR__ . '/../models/Tag.php';

$db = null;
$foreignKeysDisabled = false;

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

    foreach ($data as $section => $rows) {
        if ($section !== '_meta' && !is_array($rows)) {
            throw new InvalidArgumentException('Invalid backup section: ' . $section);
        }
    }
    if (isset($data['_meta'])) {
        if (($data['_meta']['format'] ?? '') !== 'newaccounts-backup'
            || (int)($data['_meta']['version'] ?? 0) > 6) {
            throw new InvalidArgumentException('Unsupported backup format or version.');
        }
        foreach (($data['_meta']['counts'] ?? []) as $section => $expected) {
            if (!isset($data[$section]) || count($data[$section]) !== (int)$expected) {
                throw new InvalidArgumentException('Backup row-count validation failed for ' . $section . '.');
            }
        }
    }

    $db = Database::getConnection();
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        $foreignKeysDisabled = true;
    }
    $db->beginTransaction();
    if ($driver === 'sqlite') $db->exec('PRAGMA defer_foreign_keys = ON');
    if (isset($data['transaction_tag_proposals']) || isset($data['tag_migration_runs'])) $db->exec('DELETE FROM transaction_tag_proposals');
    if (isset($data['tag_taxonomy_patterns']) || isset($data['tag_migration_runs'])) $db->exec('DELETE FROM tag_taxonomy_patterns');
    if (isset($data['tag_taxonomy_proposals']) || isset($data['tag_migration_runs'])) $db->exec('DELETE FROM tag_taxonomy_proposals');
    if (isset($data['transaction_classification_snapshots'])) $db->exec('DELETE FROM transaction_classification_snapshots');
    if (isset($data['tag_migration_runs'])) $db->exec('DELETE FROM tag_migration_runs');
    if (isset($data['category_tags'])) $db->exec('DELETE FROM category_tags');
    if (isset($data['segment_categories'])) $db->exec('DELETE FROM segment_categories');
    if (isset($data['transactions'])) $db->exec('DELETE FROM transactions');
    if (isset($data['tag_aliases'])) $db->exec('DELETE FROM tag_aliases');
    if (isset($data['projects'])) $db->exec('DELETE FROM projects');
    if (isset($data['budgets'])) $db->exec('DELETE FROM budgets');
    if (isset($data['saved_reports'])) $db->exec('DELETE FROM saved_reports');
    if (isset($data['tags'])) $db->exec('DELETE FROM tags');
    if (isset($data['categories'])) $db->exec('DELETE FROM categories');
    if (isset($data['segments'])) $db->exec('DELETE FROM segments');
    if (isset($data['groups'])) $db->exec('DELETE FROM transaction_groups');
    if (isset($data['settings'])) $db->exec('DELETE FROM settings');
    if (isset($data['passkeys'])) $db->exec('DELETE FROM passkeys');
    if (isset($data['totp_secrets'])) $db->exec('DELETE FROM totp_secrets');
    if (isset($data['accounts'])) $db->exec('DELETE FROM accounts');
    if (isset($data['users'])) $db->exec('DELETE FROM users');

    if (isset($data['users'])) {
        $stmtUser = $db->prepare('INSERT INTO users (id, username, password) VALUES (:id, :username, :password)');
        foreach ($data['users'] as $row) {
            $stmtUser->execute(['id' => $row['id'], 'username' => $row['username'], 'password' => $row['password']]);
        }
    }

    if (isset($data['passkeys'])) {
        $stmtPasskey = $db->prepare('INSERT INTO passkeys (id, user_id, credential_id, credential_id_hash, user_handle, public_key, sign_count, transports, label, backup_eligible, backed_up, created_at, last_used_at) VALUES (:id, :user_id, :credential_id, :credential_id_hash, :user_handle, :public_key, :sign_count, :transports, :label, :backup_eligible, :backed_up, :created_at, :last_used_at)');
        foreach ($data['passkeys'] as $row) {
            $credentialId = (string)($row['credential_id'] ?? '');
            if ($credentialId === '' || empty($row['user_id']) || empty($row['user_handle']) || empty($row['public_key'])) {
                throw new InvalidArgumentException('Invalid passkey backup row.');
            }
            $stmtPasskey->execute([
                'id' => $row['id'],
                'user_id' => $row['user_id'],
                'credential_id' => $credentialId,
                'credential_id_hash' => hash('sha256', $credentialId),
                'user_handle' => $row['user_handle'],
                'public_key' => $row['public_key'],
                'sign_count' => max(0, (int)($row['sign_count'] ?? 0)),
                'transports' => $row['transports'] ?? null,
                'label' => substr(trim((string)($row['label'] ?? 'Passkey')), 0, 100) ?: 'Passkey',
                'backup_eligible' => !empty($row['backup_eligible']) ? 1 : 0,
                'backed_up' => !empty($row['backed_up']) ? 1 : 0,
                'created_at' => $row['created_at'] ?? null,
                'last_used_at' => $row['last_used_at'] ?? null,
            ]);
        }
    }

    if (isset($data['totp_secrets'])) {
        $stmtTotp = $db->prepare('INSERT INTO totp_secrets (username, secret, created_at) VALUES (:username, :secret, :created_at)');
        foreach ($data['totp_secrets'] as $row) {
            $stmtTotp->execute([
                'username' => $row['username'],
                'secret' => $row['secret'],
                'created_at' => $row['created_at'] ?? null,
            ]);
        }
    }

    if (isset($data['accounts'])) {
        $stmtAcct = $db->prepare('INSERT INTO accounts (id, name, sort_code, account_number, ledger_balance, ledger_balance_date, closed, closed_at) VALUES (:id, :name, :sort_code, :account_number, :ledger_balance, :ledger_balance_date, :closed, :closed_at)');
        foreach ($data['accounts'] as $row) {
            $stmtAcct->execute([
                'id' => $row['id'],
                'name' => $row['name'],
                'sort_code' => $row['sort_code'] ?? null,
                'account_number' => $row['account_number'] ?? null,
                'ledger_balance' => !empty($row['closed']) ? 0 : $row['ledger_balance'],
                'ledger_balance_date' => $row['ledger_balance_date'] ?? null,
                'closed' => !empty($row['closed']) ? 1 : 0,
                'closed_at' => $row['closed_at'] ?? null
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
    if (isset($data['segments'])) {
        $stmtSeg = $db->prepare('INSERT INTO segments (id, name, description, created_at, updated_at) VALUES (:id, :name, :description, :created_at, :updated_at)');
        foreach ($data['segments'] as $row) {
            $stmtSeg->execute([
                'id' => $row['id'], 'name' => $row['name'],
                'description' => $row['description'] ?? null,
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ]);
        }
    }

    if (isset($data['categories'])) {
        $stmtCat = $db->prepare('INSERT INTO categories (id, segment_id, name, description, created_at, updated_at) VALUES (:id, :segment_id, :name, :description, :created_at, :updated_at)');
        foreach ($data['categories'] as $row) {
            $stmtCat->execute([
                'id' => $row['id'],
                'segment_id' => $row['segment_id'] ?? null,
                'name' => $row['name'],
                'description' => $row['description'] ?? null,
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ]);
        }
    }

    $tagIdMap = [];
    if (isset($data['tags'])) {
        $stmtTag = $db->prepare('INSERT INTO tags (id, name, name_normalized, keyword, description, origin, status, merged_into_tag_id, created_at, updated_at) VALUES (:id, :name, :name_normalized, :keyword, :description, :origin, :status, NULL, :created_at, :updated_at)');
        $canonicalTagIds = [];
        $pendingMergedTags = [];
        foreach ($data['tags'] as $row) {
            $oldTagId = (int)$row['id'];
            $normalizedName = Tag::normalizeName((string)$row['name']);
            if (isset($canonicalTagIds[$normalizedName])) {
                $tagIdMap[$oldTagId] = $canonicalTagIds[$normalizedName];
                continue;
            }
            $stmtTag->execute([
                'id' => $oldTagId,
                'name' => $row['name'],
                'name_normalized' => $normalizedName,
                'keyword' => $row['keyword'] ?? null,
                'description' => $row['description'] ?? null,
                'origin' => in_array(($row['origin'] ?? 'legacy'), ['system', 'manual', 'ai', 'legacy'], true) ? ($row['origin'] ?? 'legacy') : 'legacy',
                'status' => in_array(($row['status'] ?? 'active'), ['proposed', 'active', 'deprecated', 'merged'], true) ? ($row['status'] ?? 'active') : 'active',
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ]);
            $canonicalTagIds[$normalizedName] = $oldTagId;
            $tagIdMap[$oldTagId] = $oldTagId;
            if (!empty($row['merged_into_tag_id'])) {
                $pendingMergedTags[$oldTagId] = (int)$row['merged_into_tag_id'];
            }
        }
        if ($pendingMergedTags) {
            $updateMergedTag = $db->prepare('UPDATE tags SET merged_into_tag_id = :merged_into WHERE id = :id');
            foreach ($pendingMergedTags as $tagId => $mergedIntoTagId) {
                $destination = $tagIdMap[$mergedIntoTagId] ?? null;
                if ($destination !== null && $destination !== $tagId) {
                    $updateMergedTag->execute(['merged_into' => $destination, 'id' => $tagId]);
                }
            }
        }
    }

    if (isset($data['tag_aliases'])) {
        $stmtAlias = $db->prepare('INSERT INTO tag_aliases (id, tag_id, alias, alias_normalized, match_type, direction, active, origin, confidence, support_count, last_matched_at, created_at, updated_at) VALUES (:id, :tag_id, :alias, :alias_normalized, :match_type, :direction, :active, :origin, :confidence, :support_count, :last_matched_at, :created_at, :updated_at)');
        foreach ($data['tag_aliases'] as $row) {
            $alias = trim((string)($row['alias'] ?? ''));
            if ($alias === '') {
                continue;
            }

            $stmtAlias->execute([
                'id' => $row['id'],
                'tag_id' => $tagIdMap[(int)$row['tag_id']] ?? $row['tag_id'],
                'alias' => $alias,
                'alias_normalized' => TagAlias::normalizeAlias($alias),
                'match_type' => ($row['match_type'] ?? 'contains') === 'exact' ? 'exact' : 'contains',
                'direction' => TagAlias::normalizeDirection((string)($row['direction'] ?? 'any')),
                'active' => isset($row['active']) ? (int)$row['active'] : 1,
                'origin' => in_array(($row['origin'] ?? 'legacy'), ['system', 'manual', 'ai', 'legacy'], true) ? ($row['origin'] ?? 'legacy') : 'legacy',
                'confidence' => isset($row['confidence']) && is_numeric($row['confidence']) ? max(0, min(1, (float)$row['confidence'])) : null,
                'support_count' => max(0, (int)($row['support_count'] ?? 0)),
                'last_matched_at' => $row['last_matched_at'] ?? null,
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
        $stmtBud = $db->prepare('INSERT INTO budgets (id, category_id, month, year, amount) VALUES (:id, :category_id, :month, :year, :amount)');
        foreach ($data['budgets'] as $row) {
            $stmtBud->execute([
                'id' => $row['id'] ?? null,
                'category_id' => $row['category_id'],
                'month' => $row['month'],
                'year' => $row['year'],
                'amount' => $row['amount']
            ]);
        }
    }

    if (isset($data['transactions'])) {
        $stmtTx = $db->prepare('INSERT INTO transactions (id, account_id, date, amount, description, memo, category_id, segment_id, tag_id, group_id, transfer_id, ofx_id, ofx_type, bank_ofx_id) VALUES (:id, :account_id, :date, :amount, :description, :memo, :category_id, :segment_id, :tag_id, :group_id, :transfer_id, :ofx_id, :ofx_type, :bank_ofx_id)');
        foreach ($data['transactions'] as $row) {
            $stmtTx->execute([
                'id' => $row['id'],
                'account_id' => $row['account_id'],
                'date' => $row['date'],
                'amount' => $row['amount'],
                'description' => $row['description'],
                'memo' => $row['memo'],
                'category_id' => $row['category_id'],
                'segment_id' => $row['segment_id'] ?? null,
                'tag_id' => $row['tag_id'] === null ? null : ($tagIdMap[(int)$row['tag_id']] ?? $row['tag_id']),
                'group_id' => $row['group_id'],
                'transfer_id' => $row['transfer_id'],
                'ofx_id' => $row['ofx_id'],
                'ofx_type' => $row['ofx_type'] ?? null,
                'bank_ofx_id' => $row['bank_ofx_id'] ?? null
            ]);
        }
    }

    if (isset($data['category_tags'])) {
        $stmtCT = $db->prepare('INSERT INTO category_tags (category_id, tag_id) VALUES (:category_id, :tag_id)');
        foreach ($data['category_tags'] as $row) {
            $stmtCT->execute([
                'category_id' => $row['category_id'],
                'tag_id' => $tagIdMap[(int)$row['tag_id']] ?? $row['tag_id']
            ]);
        }
    }
    if (isset($data['segment_categories'])) {
        $stmtSC = $db->prepare('INSERT INTO segment_categories (segment_id, category_id) VALUES (:segment_id, :category_id)');
        foreach ($data['segment_categories'] as $row) {
            $stmtSC->execute(['segment_id' => $row['segment_id'], 'category_id' => $row['category_id']]);
        }
    }
    if (isset($data['saved_reports'])) {
        $stmtReport = $db->prepare('INSERT INTO saved_reports (id, name, description, filters, created_at) VALUES (:id, :name, :description, :filters, :created_at)');
        foreach ($data['saved_reports'] as $row) {
            $stmtReport->execute([
                'id' => $row['id'], 'name' => $row['name'],
                'description' => $row['description'] ?? null,
                'filters' => $row['filters'], 'created_at' => $row['created_at'] ?? null,
            ]);
        }
    }

    if (isset($data['tag_migration_runs'])) {
        $stmtMigrationRun = $db->prepare('INSERT INTO tag_migration_runs (id, name, status, contract_version, created_by, transaction_count, eligible_count, protected_transfer_count, protected_ignore_count, snapshot_hash, created_at, discovery_started_at, ready_at, applied_at, rolled_back_at, cutover_summary) VALUES (:id, :name, :status, :contract_version, :created_by, :transaction_count, :eligible_count, :protected_transfer_count, :protected_ignore_count, :snapshot_hash, :created_at, :discovery_started_at, :ready_at, :applied_at, :rolled_back_at, :cutover_summary)');
        $validRunStatuses = ['snapshot', 'staging', 'ready', 'applied', 'rolled_back', 'cancelled'];
        foreach ($data['tag_migration_runs'] as $row) {
            $stmtMigrationRun->execute([
                'id' => $row['id'],
                'name' => $row['name'],
                'status' => in_array(($row['status'] ?? 'snapshot'), $validRunStatuses, true) ? ($row['status'] ?? 'snapshot') : 'snapshot',
                'contract_version' => $row['contract_version'] ?? 'v1',
                'created_by' => $row['created_by'] ?? null,
                'transaction_count' => (int)($row['transaction_count'] ?? 0),
                'eligible_count' => (int)($row['eligible_count'] ?? 0),
                'protected_transfer_count' => (int)($row['protected_transfer_count'] ?? 0),
                'protected_ignore_count' => (int)($row['protected_ignore_count'] ?? 0),
                'snapshot_hash' => $row['snapshot_hash'] ?? '',
                'created_at' => $row['created_at'] ?? null,
                'discovery_started_at' => $row['discovery_started_at'] ?? null,
                'ready_at' => $row['ready_at'] ?? null,
                'applied_at' => $row['applied_at'] ?? null,
                'rolled_back_at' => $row['rolled_back_at'] ?? null,
                'cutover_summary' => $row['cutover_summary'] ?? null,
            ]);
        }
    }
    if (isset($data['transaction_classification_snapshots'])) {
        $stmtClassificationSnapshot = $db->prepare('INSERT INTO transaction_classification_snapshots (run_id, transaction_id, tag_id, category_id, segment_id, eligible, protection_reason, created_at) VALUES (:run_id, :transaction_id, :tag_id, :category_id, :segment_id, :eligible, :protection_reason, :created_at)');
        foreach ($data['transaction_classification_snapshots'] as $row) {
            $reason = $row['protection_reason'] ?? null;
            $stmtClassificationSnapshot->execute([
                'run_id' => $row['run_id'],
                'transaction_id' => $row['transaction_id'],
                'tag_id' => $row['tag_id'] === null ? null : ($tagIdMap[(int)$row['tag_id']] ?? $row['tag_id']),
                'category_id' => $row['category_id'] ?? null,
                'segment_id' => $row['segment_id'] ?? null,
                'eligible' => !empty($row['eligible']) ? 1 : 0,
                'protection_reason' => in_array($reason, ['transfer', 'ignored'], true) ? $reason : null,
                'created_at' => $row['created_at'] ?? null,
            ]);
        }
    }
    if (isset($data['tag_taxonomy_proposals'])) {
        $stmtTaxonomyProposal = $db->prepare('INSERT INTO tag_taxonomy_proposals (id, run_id, canonical_name, canonical_name_normalized, description, category_id, confidence, rationale, status, origin, pattern_count, transaction_count, absolute_amount, reviewed_by, reviewed_at, created_at, updated_at) VALUES (:id, :run_id, :canonical_name, :canonical_name_normalized, :description, :category_id, :confidence, :rationale, :status, :origin, :pattern_count, :transaction_count, :absolute_amount, :reviewed_by, :reviewed_at, :created_at, :updated_at)');
        foreach ($data['tag_taxonomy_proposals'] as $row) {
            $status = $row['status'] ?? 'pending';
            $stmtTaxonomyProposal->execute([
                'id' => $row['id'],
                'run_id' => $row['run_id'],
                'canonical_name' => $row['canonical_name'],
                'canonical_name_normalized' => $row['canonical_name_normalized'] ?? Tag::normalizeName((string)$row['canonical_name']),
                'description' => $row['description'] ?? null,
                'category_id' => $row['category_id'] ?? null,
                'confidence' => $row['confidence'] ?? null,
                'rationale' => $row['rationale'] ?? null,
                'status' => in_array($status, ['pending', 'approved', 'rejected'], true) ? $status : 'pending',
                'origin' => ($row['origin'] ?? 'ai') === 'manual' ? 'manual' : 'ai',
                'pattern_count' => (int)($row['pattern_count'] ?? 0),
                'transaction_count' => (int)($row['transaction_count'] ?? 0),
                'absolute_amount' => $row['absolute_amount'] ?? 0,
                'reviewed_by' => $row['reviewed_by'] ?? null,
                'reviewed_at' => $row['reviewed_at'] ?? null,
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ]);
        }
    }
    if (isset($data['tag_taxonomy_patterns'])) {
        $stmtTaxonomyPattern = $db->prepare('INSERT INTO tag_taxonomy_patterns (id, run_id, proposal_id, signature, alias, alias_normalized, direction, sample_description, sample_memo, current_tags, transaction_count, absolute_amount, first_seen, last_seen, confidence, rationale, status, created_at, updated_at) VALUES (:id, :run_id, :proposal_id, :signature, :alias, :alias_normalized, :direction, :sample_description, :sample_memo, :current_tags, :transaction_count, :absolute_amount, :first_seen, :last_seen, :confidence, :rationale, :status, :created_at, :updated_at)');
        foreach ($data['tag_taxonomy_patterns'] as $row) {
            $status = $row['status'] ?? 'pending';
            $stmtTaxonomyPattern->execute([
                'id' => $row['id'],
                'run_id' => $row['run_id'],
                'proposal_id' => $row['proposal_id'] ?? null,
                'signature' => $row['signature'],
                'alias' => $row['alias'],
                'alias_normalized' => $row['alias_normalized'],
                'direction' => ($row['direction'] ?? 'outgoing') === 'incoming' ? 'incoming' : 'outgoing',
                'sample_description' => $row['sample_description'] ?? null,
                'sample_memo' => $row['sample_memo'] ?? null,
                'current_tags' => $row['current_tags'] ?? null,
                'transaction_count' => (int)($row['transaction_count'] ?? 0),
                'absolute_amount' => $row['absolute_amount'] ?? 0,
                'first_seen' => $row['first_seen'] ?? null,
                'last_seen' => $row['last_seen'] ?? null,
                'confidence' => $row['confidence'] ?? null,
                'rationale' => $row['rationale'] ?? null,
                'status' => in_array($status, ['pending', 'proposed', 'excluded'], true) ? $status : 'pending',
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ]);
        }
    }
    if (isset($data['transaction_tag_proposals'])) {
        $stmtTransactionTagProposal = $db->prepare('INSERT INTO transaction_tag_proposals (run_id, transaction_id, pattern_id, proposal_id, current_tag_id, confidence, created_at) VALUES (:run_id, :transaction_id, :pattern_id, :proposal_id, :current_tag_id, :confidence, :created_at)');
        foreach ($data['transaction_tag_proposals'] as $row) {
            $stmtTransactionTagProposal->execute([
                'run_id' => $row['run_id'],
                'transaction_id' => $row['transaction_id'],
                'pattern_id' => $row['pattern_id'],
                'proposal_id' => $row['proposal_id'] ?? null,
                'current_tag_id' => $row['current_tag_id'] === null ? null : ($tagIdMap[(int)$row['current_tag_id']] ?? $row['current_tag_id']),
                'confidence' => $row['confidence'] ?? null,
                'created_at' => $row['created_at'] ?? null,
            ]);
        }
    }

    $orphanChecks = [
        'transaction accounts' => 'SELECT COUNT(*) FROM transactions t LEFT JOIN accounts a ON a.id=t.account_id WHERE a.id IS NULL',
        'transaction categories' => 'SELECT COUNT(*) FROM transactions t LEFT JOIN categories c ON c.id=t.category_id WHERE t.category_id IS NOT NULL AND c.id IS NULL',
        'transaction tags' => 'SELECT COUNT(*) FROM transactions t LEFT JOIN tags x ON x.id=t.tag_id WHERE t.tag_id IS NOT NULL AND x.id IS NULL',
        'transaction groups' => 'SELECT COUNT(*) FROM transactions t LEFT JOIN transaction_groups g ON g.id=t.group_id WHERE t.group_id IS NOT NULL AND g.id IS NULL',
        'transaction segments' => 'SELECT COUNT(*) FROM transactions t LEFT JOIN segments s ON s.id=t.segment_id WHERE t.segment_id IS NOT NULL AND s.id IS NULL',
        'taxonomy pattern runs' => 'SELECT COUNT(*) FROM tag_taxonomy_patterns p LEFT JOIN tag_migration_runs r ON r.id=p.run_id WHERE r.id IS NULL',
        'taxonomy transaction patterns' => 'SELECT COUNT(*) FROM transaction_tag_proposals t LEFT JOIN tag_taxonomy_patterns p ON p.id=t.pattern_id WHERE p.id IS NULL',
    ];
    foreach ($orphanChecks as $label => $sql) {
        if ((int)$db->query($sql)->fetchColumn() > 0) {
            throw new RuntimeException('Restore integrity check failed for ' . $label . '.');
        }
    }
    if ($driver === 'pgsql') {
        foreach (['users', 'passkeys', 'saved_reports', 'accounts', 'segments', 'categories', 'budgets', 'projects', 'tags', 'tag_aliases', 'transaction_groups', 'transactions', 'tag_migration_runs', 'tag_taxonomy_proposals', 'tag_taxonomy_patterns', 'logs'] as $table) {
            $db->exec("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), COALESCE((SELECT MAX(id) FROM \"{$table}\"), 1), true)");
        }
    }
    $db->commit();
    if ($foreignKeysDisabled) {
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
        $foreignKeysDisabled = false;
    }
    Log::write('Restore completed for parts: ' . implode(',', array_keys($data)));
    echo 'Restore complete.';
} catch (Exception $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    if ($foreignKeysDisabled && $db instanceof PDO) {
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
    }
    Log::write('Restore error: ' . $e->getMessage(), 'ERROR');
    if (!headers_sent()) http_response_code(500);
    $msg = 'Error: ' . $e->getMessage();
    Log::write($msg, 'ERROR');
    echo $msg;
}
