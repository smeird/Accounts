<?php
// Exports selected data as JSON. Allows selecting categories, tags (including tag aliases), groups,
// segments, transactions, budgets, projects, and settings via the `parts`
// query parameter. User and account information is always included so a full
// backup can be restored.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../models/Log.php';

// Determine which parts are being backed up so the filename can reflect them
// Include segments so they can be exported and restored
// Allow optionally including projects in the backup
// Settings may also be backed up and restored
$allParts = ['categories','tags','groups','transactions','budgets','segments','projects','settings','reports','tag_migrations'];
$parts = isset($_GET['parts']) && $_GET['parts'] !== ''
    ? array_intersect($allParts, explode(',', $_GET['parts']))
    : $allParts;
$partSlug = preg_replace('/[^A-Za-z0-9_-]/', '_', implode('-', $parts));

// Send a gzipped JSON file with a descriptive filename
if (!headers_sent()) header('Content-Type: application/gzip');
$host = $_SERVER['HTTP_HOST'] ?? 'backup';
$host = preg_replace('/[^A-Za-z0-9_-]/', '_', $host);
$filename = $host . '-' . date('Y-m-d') . '-' . $partSlug . '.json.gz';
if (!headers_sent()) header('Content-Disposition: attachment; filename="' . $filename . '"');

try {
    $db = Database::getConnection();

    $getAll = function(string $sql) use ($db) {
        $stmt = $db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };

    $data = [];
    $data['_meta'] = [
        'format' => 'newaccounts-backup',
        'version' => 3,
        'created_at' => gmdate('c'),
        'parts' => array_values($parts),
    ];
    // Always include users and account details
    $data['users'] = $getAll('SELECT id, username, password FROM users ORDER BY id');
    $data['totp_secrets'] = $getAll('SELECT username, secret, created_at FROM totp_secrets ORDER BY username');
    $data['accounts'] = $getAll('SELECT id, name, sort_code, account_number, ledger_balance, ledger_balance_date, closed, closed_at FROM accounts ORDER BY id');
    if (in_array('settings', $parts)) {
        $data['settings'] = $getAll('SELECT name, value FROM settings ORDER BY name');
    }
    if (in_array('categories', $parts)) {
        // Include segment references with categories
        $data['categories'] = $getAll('SELECT id, segment_id, name, description, created_at, updated_at FROM categories ORDER BY id');
    }
    if (in_array('tags', $parts)) {
        $data['tags'] = $getAll('SELECT id, name, name_normalized, keyword, description, origin, status, merged_into_tag_id, created_at, updated_at FROM tags ORDER BY id');
        $data['tag_aliases'] = $getAll('SELECT id, tag_id, alias, alias_normalized, match_type, active, origin, confidence, support_count, last_matched_at, created_at, updated_at FROM tag_aliases ORDER BY id');
    }
    if (in_array('categories', $parts) || in_array('tags', $parts)) {
        $data['category_tags'] = $getAll('SELECT category_id, tag_id FROM category_tags ORDER BY category_id, tag_id');
    }
    if (in_array('groups', $parts)) {
        $data['groups'] = $getAll('SELECT id, name, description, active FROM transaction_groups ORDER BY id');
    }
    if (in_array('segments', $parts)) {
        $data['segments'] = $getAll('SELECT id, name, description, created_at, updated_at FROM segments ORDER BY id');
    }
    if (in_array('segments', $parts) || in_array('categories', $parts)) {
        $data['segment_categories'] = $getAll('SELECT segment_id, category_id FROM segment_categories ORDER BY segment_id, category_id');
    }
    if (in_array('transactions', $parts)) {
        $data['transactions'] = $getAll('SELECT id, account_id, date, amount, description, memo, category_id, segment_id, tag_id, group_id, transfer_id, ofx_id, ofx_type, bank_ofx_id FROM transactions ORDER BY id');
    }
    if (in_array('budgets', $parts)) {
        $data['budgets'] = $getAll('SELECT id, category_id, month, year, amount FROM budgets ORDER BY id');
    }
    if (in_array('reports', $parts)) {
        $data['saved_reports'] = $getAll('SELECT id, name, description, filters, created_at FROM saved_reports ORDER BY id');
    }

    if (in_array('projects', $parts)) {
        $data['projects'] = $getAll('SELECT id, name, description, rationale, cost_low, cost_medium, cost_high, funding_source, recurring_cost, estimated_time, expected_lifespan, benefit_financial, benefit_quality, benefit_risk, benefit_sustainability, weight_financial, weight_quality, weight_risk, weight_sustainability, dependencies, risks, archived, group_id, created_at FROM projects ORDER BY id');
    }
    if (in_array('tag_migrations', $parts)) {
        $data['tag_migration_runs'] = $getAll('SELECT id, name, status, contract_version, created_by, transaction_count, eligible_count, protected_transfer_count, protected_ignore_count, snapshot_hash, created_at, applied_at, rolled_back_at FROM tag_migration_runs ORDER BY id');
        $data['transaction_classification_snapshots'] = $getAll('SELECT run_id, transaction_id, tag_id, category_id, segment_id, eligible, protection_reason, created_at FROM transaction_classification_snapshots ORDER BY run_id, transaction_id');
    }

    $data['_meta']['counts'] = [];
    foreach ($data as $key => $rows) {
        if ($key !== '_meta' && is_array($rows)) {
            $data['_meta']['counts'][$key] = count($rows);
        }
    }

    // Compress the JSON payload
    $json = json_encode($data);
    if ($json === false) {
        throw new RuntimeException('Unable to encode backup: ' . json_last_error_msg());
    }
    $gz = gzencode($json);
    if ($gz === false) {
        throw new RuntimeException('Unable to compress backup.');
    }
    // Log before sending output to avoid corrupting the gzip stream
    Log::write('Backup generated with parts: ' . implode(',', $parts));
    echo $gz;
} catch (Exception $e) {
    Log::write('Backup error: ' . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo gzencode(json_encode(['error' => $e->getMessage()]));
}
