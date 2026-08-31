<?php
// Resets and creates all database tables used by the application.
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/SchemaCatalog.php';
require_once __DIR__ . '/models/Transaction.php';
require_once __DIR__ . '/models/Log.php';

$db = Database::getConnection();

// Drop existing tables to ensure a clean state
$db->exec("SET FOREIGN_KEY_CHECKS=0");
$dropSql = <<<SQL
DROP TABLE IF EXISTS logs;
DROP TABLE IF EXISTS transaction_tag_proposals;
DROP TABLE IF EXISTS tag_taxonomy_patterns;
DROP TABLE IF EXISTS tag_taxonomy_proposals;
DROP TABLE IF EXISTS transaction_classification_snapshots;
DROP TABLE IF EXISTS tag_migration_runs;
DROP TABLE IF EXISTS saved_reports;
DROP TABLE IF EXISTS passkeys;
DROP TABLE IF EXISTS totp_secrets;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS transactions;
DROP TABLE IF EXISTS transaction_groups;
DROP TABLE IF EXISTS category_tags;
DROP TABLE IF EXISTS tag_aliases;
DROP TABLE IF EXISTS tags;
DROP TABLE IF EXISTS budgets;
DROP TABLE IF EXISTS projects;
DROP TABLE IF EXISTS segment_categories;
DROP TABLE IF EXISTS segments;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS accounts;
SQL;
$db->exec($dropSql);
$db->exec("SET FOREIGN_KEY_CHECKS=1");

$createSql = <<<SQL
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL
);

CREATE TABLE IF NOT EXISTS passkeys (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    credential_id TEXT NOT NULL,
    credential_id_hash CHAR(64) NOT NULL UNIQUE,
    user_handle VARCHAR(86) NOT NULL,
    public_key TEXT NOT NULL,
    sign_count BIGINT NOT NULL DEFAULT 0,
    transports VARCHAR(255) DEFAULT NULL,
    label VARCHAR(100) NOT NULL DEFAULT 'Passkey',
    backup_eligible TINYINT(1) NOT NULL DEFAULT 0,
    backed_up TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NULL DEFAULT NULL,
    KEY idx_passkeys_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS totp_secrets (
    username VARCHAR(100) PRIMARY KEY,
    secret VARCHAR(64) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS settings (
    name VARCHAR(100) PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS saved_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    filters TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    sort_code VARCHAR(20) DEFAULT NULL,
    account_number VARCHAR(50) DEFAULT NULL,
    ledger_balance DECIMAL(10,2) DEFAULT 0,
    ledger_balance_date DATE DEFAULT NULL,
    closed TINYINT NOT NULL DEFAULT 0,
    closed_at DATE DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS segments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT DEFAULT NULL,
    segment_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (segment_id) REFERENCES segments(id)
);

CREATE TABLE IF NOT EXISTS budgets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    month TINYINT NOT NULL,
    year INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    UNIQUE KEY unique_budget (category_id, month, year),
    FOREIGN KEY (category_id) REFERENCES categories(id)
);

CREATE TABLE IF NOT EXISTS projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    rationale TEXT DEFAULT NULL,
    cost_low DECIMAL(10,2) DEFAULT NULL,
    cost_medium DECIMAL(10,2) DEFAULT NULL,
    cost_high DECIMAL(10,2) DEFAULT NULL,
    funding_source VARCHAR(100) DEFAULT NULL,
    recurring_cost DECIMAL(10,2) DEFAULT NULL,
    estimated_time INT DEFAULT NULL,
    expected_lifespan INT DEFAULT NULL,
    benefit_financial TINYINT DEFAULT 0,
    benefit_quality TINYINT DEFAULT 0,
    benefit_risk TINYINT DEFAULT 0,
    benefit_sustainability TINYINT DEFAULT 0,
    weight_financial TINYINT DEFAULT 1,
    weight_quality TINYINT DEFAULT 1,
    weight_risk TINYINT DEFAULT 1,
    weight_sustainability TINYINT DEFAULT 1,
    dependencies TEXT DEFAULT NULL,
    risks TEXT DEFAULT NULL,
    archived TINYINT DEFAULT 0,
    group_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);


CREATE TABLE IF NOT EXISTS segment_categories (
    segment_id INT NOT NULL,
    category_id INT NOT NULL,
    PRIMARY KEY (segment_id, category_id),
    FOREIGN KEY (segment_id) REFERENCES segments(id),
    FOREIGN KEY (category_id) REFERENCES categories(id)
);


CREATE TABLE IF NOT EXISTS tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    name_normalized VARCHAR(100) DEFAULT NULL,
    keyword VARCHAR(100) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    UNIQUE KEY unique_tag_name_normalized (name_normalized)
);

CREATE TABLE IF NOT EXISTS tag_aliases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tag_id INT NOT NULL,
    alias VARCHAR(150) NOT NULL,
    alias_normalized VARCHAR(150) NOT NULL,
    match_type ENUM('exact', 'contains') NOT NULL DEFAULT 'contains',
    direction ENUM('any', 'outgoing', 'incoming') NOT NULL DEFAULT 'any',
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_alias_direction (alias_normalized, direction),
    KEY idx_tag_aliases_tag_id (tag_id),
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS category_tags (
    category_id INT NOT NULL,
    tag_id INT NOT NULL,
    PRIMARY KEY (category_id, tag_id),
    FOREIGN KEY (category_id) REFERENCES categories(id),
    FOREIGN KEY (tag_id) REFERENCES tags(id)
);

CREATE TABLE IF NOT EXISTS transaction_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT DEFAULT NULL,
    active TINYINT DEFAULT 1
);

CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    date DATE NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    description VARCHAR(255) NOT NULL,
    memo VARCHAR(255) DEFAULT NULL,
    category_id INT DEFAULT NULL,
    segment_id INT DEFAULT NULL,
    tag_id INT DEFAULT NULL,
    group_id INT DEFAULT NULL,
    transfer_id INT DEFAULT NULL,
    ofx_id VARCHAR(255) UNIQUE,
    ofx_type VARCHAR(50) DEFAULT NULL,
    bank_ofx_id VARCHAR(255) DEFAULT NULL,
    UNIQUE KEY unique_bank_fitid (account_id, bank_ofx_id),
    KEY idx_transaction_fallback (account_id, date, amount),

    FOREIGN KEY (account_id) REFERENCES accounts(id),
    FOREIGN KEY (category_id) REFERENCES categories(id),
    FOREIGN KEY (segment_id) REFERENCES segments(id),
    FOREIGN KEY (tag_id) REFERENCES tags(id),
    FOREIGN KEY (group_id) REFERENCES transaction_groups(id)
);

CREATE TABLE IF NOT EXISTS logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    level VARCHAR(10) NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
SQL;

// Keep fresh installs and the non-destructive Database Health utility on one
// canonical schema definition. The catalogue supersedes the legacy literal
// block above while it remains in place for readable historical context.
$createSql = implode(";\n", array_values(SchemaCatalog::createStatements())) . ';';
$db->exec($createSql);

// Ensure keyword column exists if the tags table pre-dates it
$result = $db->query("SHOW COLUMNS FROM `tags` LIKE 'keyword'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `tags` ADD COLUMN `keyword` VARCHAR(100) DEFAULT NULL");
}

// Ensure description column exists in tags
$result = $db->query("SHOW COLUMNS FROM `tags` LIKE 'description'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `tags` ADD COLUMN `description` TEXT DEFAULT NULL");
}

// Ensure name_normalized column exists in tags
$result = $db->query("SHOW COLUMNS FROM `tags` LIKE 'name_normalized'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `tags` ADD COLUMN `name_normalized` VARCHAR(100) DEFAULT NULL");
}

// Backfill normalized tag names and safely merge duplicates
$tagRows = $db->query('SELECT `id`, `name`, `name_normalized` FROM `tags`')->fetchAll(PDO::FETCH_ASSOC);
$updateNormalizedTagName = $db->prepare('UPDATE `tags` SET `name_normalized` = :normalized WHERE `id` = :id');
foreach ($tagRows as $tagRow) {
    if ($tagRow['name_normalized'] !== null && trim($tagRow['name_normalized']) !== '') {
        continue;
    }
    $normalizedTagName = strtolower(trim(preg_replace('/\s+/', ' ', (string)$tagRow['name'])));
    $updateNormalizedTagName->execute(['normalized' => $normalizedTagName, 'id' => (int)$tagRow['id']]);
}

$duplicateRows = $db->query("SELECT `name_normalized` FROM `tags` WHERE `name_normalized` IS NOT NULL AND `name_normalized` != '' GROUP BY `name_normalized` HAVING COUNT(*) > 1")->fetchAll(PDO::FETCH_COLUMN);
if (!empty($duplicateRows)) {
    $moveTransactions = $db->prepare('UPDATE `transactions` SET `tag_id` = :keep_id WHERE `tag_id` = :drop_id');
    $copyAliases = $db->prepare('INSERT IGNORE INTO `tag_aliases` (`tag_id`, `alias`, `alias_normalized`, `match_type`, `active`, `created_at`, `updated_at`) SELECT :keep_id, `alias`, `alias_normalized`, `match_type`, `active`, `created_at`, `updated_at` FROM `tag_aliases` WHERE `tag_id` = :drop_id');
    $deleteAliases = $db->prepare('DELETE FROM `tag_aliases` WHERE `tag_id` = :drop_id');
    $copyCategoryTag = $db->prepare('INSERT IGNORE INTO `category_tags` (`category_id`, `tag_id`) SELECT `category_id`, :keep_id FROM `category_tags` WHERE `tag_id` = :drop_id');
    $deleteCategoryTag = $db->prepare('DELETE FROM `category_tags` WHERE `tag_id` = :drop_id');
    $deleteTag = $db->prepare('DELETE FROM `tags` WHERE `id` = :drop_id');

    foreach ($duplicateRows as $normalizedName) {
        $stmt = $db->prepare('SELECT `id` FROM `tags` WHERE `name_normalized` = :normalized ORDER BY `id` ASC');
        $stmt->execute(['normalized' => $normalizedName]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) < 2) {
            continue;
        }

        $keepId = (int)$ids[0];
        for ($i = 1; $i < count($ids); $i++) {
            $dropId = (int)$ids[$i];
            $moveTransactions->execute(['keep_id' => $keepId, 'drop_id' => $dropId]);
            $copyAliases->execute(['keep_id' => $keepId, 'drop_id' => $dropId]);
            $deleteAliases->execute(['drop_id' => $dropId]);
            $copyCategoryTag->execute(['keep_id' => $keepId, 'drop_id' => $dropId]);
            $deleteCategoryTag->execute(['drop_id' => $dropId]);
            $deleteTag->execute(['drop_id' => $dropId]);
        }
    }
}

$result = $db->query("SHOW INDEX FROM `tags` WHERE Key_name = 'unique_tag_name_normalized'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `tags` ADD UNIQUE KEY `unique_tag_name_normalized` (`name_normalized`)");
}

// Ensure tag_aliases table exists for descriptor-to-tag mapping
$result = $db->query("SHOW TABLES LIKE 'tag_aliases'");
if ($result->rowCount() === 0) {
    $db->exec("CREATE TABLE `tag_aliases` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `tag_id` INT NOT NULL,
        `alias` VARCHAR(150) NOT NULL,
        `alias_normalized` VARCHAR(150) NOT NULL,
        `match_type` ENUM('exact','contains') NOT NULL DEFAULT 'contains',
        `direction` ENUM('any','outgoing','incoming') NOT NULL DEFAULT 'any',
        `active` TINYINT(1) DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_alias_direction` (`alias_normalized`, `direction`),
        KEY `idx_tag_aliases_tag_id` (`tag_id`),
        FOREIGN KEY (`tag_id`) REFERENCES `tags`(`id`) ON DELETE CASCADE
    )");
}

// Ensure alias_normalized column exists in tag_aliases
$result = $db->query("SHOW COLUMNS FROM `tag_aliases` LIKE 'alias_normalized'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `tag_aliases` ADD COLUMN `alias_normalized` VARCHAR(150) NOT NULL DEFAULT ''");
}

// Ensure match_type column exists in tag_aliases
$result = $db->query("SHOW COLUMNS FROM `tag_aliases` LIKE 'match_type'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `tag_aliases` ADD COLUMN `match_type` ENUM('exact','contains') NOT NULL DEFAULT 'contains'");
}

// Direction-specific rules allow identical bank wording to resolve differently
// for money leaving and arriving. Legacy rules remain valid for either direction.
$result = $db->query("SHOW COLUMNS FROM `tag_aliases` LIKE 'direction'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `tag_aliases` ADD COLUMN `direction` ENUM('any','outgoing','incoming') NOT NULL DEFAULT 'any' AFTER `match_type`");
}

// Ensure active column exists in tag_aliases
$result = $db->query("SHOW COLUMNS FROM `tag_aliases` LIKE 'active'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `tag_aliases` ADD COLUMN `active` TINYINT(1) DEFAULT 1");
}

// Ensure timestamps exist in tag_aliases
$result = $db->query("SHOW COLUMNS FROM `tag_aliases` LIKE 'created_at'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `tag_aliases` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
}

$result = $db->query("SHOW COLUMNS FROM `tag_aliases` LIKE 'updated_at'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `tag_aliases` ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
}

// Backfill normalized aliases and enforce uniqueness/indexing
$db->exec("UPDATE `tag_aliases` SET `alias_normalized` = LOWER(TRIM(`alias`)) WHERE `alias_normalized` = '' OR `alias_normalized` IS NULL");

$result = $db->query("SHOW INDEX FROM `tag_aliases` WHERE Key_name = 'unique_alias_normalized'");
if ($result->rowCount() > 0) {
    $db->exec("ALTER TABLE `tag_aliases` DROP INDEX `unique_alias_normalized`");
}

$result = $db->query("SHOW INDEX FROM `tag_aliases` WHERE Key_name = 'unique_alias_direction'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `tag_aliases` ADD UNIQUE KEY `unique_alias_direction` (`alias_normalized`, `direction`)");
}

$result = $db->query("SHOW INDEX FROM `tag_aliases` WHERE Key_name = 'idx_tag_aliases_tag_id'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `tag_aliases` ADD KEY `idx_tag_aliases_tag_id` (`tag_id`)");
}

// Ensure description column exists in categories
$result = $db->query("SHOW COLUMNS FROM `categories` LIKE 'description'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `categories` ADD COLUMN `description` TEXT DEFAULT NULL");
}

// Ensure segments table exists
$result = $db->query("SHOW TABLES LIKE 'segments'");
if ($result->rowCount() === 0) {
    $db->exec("CREATE TABLE `segments` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        description TEXT DEFAULT NULL
    )");
}

// Ensure segment_id column exists in categories
$result = $db->query("SHOW COLUMNS FROM `categories` LIKE 'segment_id'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `categories` ADD COLUMN `segment_id` INT DEFAULT NULL");
    $db->exec("ALTER TABLE `categories` ADD FOREIGN KEY (`segment_id`) REFERENCES `segments`(`id`)");
}

// Ensure segment_id column exists in transactions
$result = $db->query("SHOW COLUMNS FROM `transactions` LIKE 'segment_id'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `transactions` ADD COLUMN `segment_id` INT DEFAULT NULL");
    $db->exec("ALTER TABLE `transactions` ADD FOREIGN KEY (`segment_id`) REFERENCES `segments`(`id`)");
}

// Ensure description column exists in transaction_groups
$result = $db->query("SHOW COLUMNS FROM `transaction_groups` LIKE 'description'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `transaction_groups` ADD COLUMN `description` TEXT DEFAULT NULL");
}

// Ensure active column exists in transaction_groups
$result = $db->query("SHOW COLUMNS FROM `transaction_groups` LIKE 'active'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `transaction_groups` ADD COLUMN `active` TINYINT DEFAULT 1");
}

// Ensure transfer_id column exists in transactions
$result = $db->query("SHOW COLUMNS FROM `transactions` LIKE 'transfer_id'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `transactions` ADD COLUMN `transfer_id` INT DEFAULT NULL");
}

// Ensure ofx_type column exists in transactions
$result = $db->query("SHOW COLUMNS FROM `transactions` LIKE 'ofx_type'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `transactions` ADD COLUMN `ofx_type` VARCHAR(50) DEFAULT NULL");
}

// Ensure bank_ofx_id column exists in transactions
$result = $db->query("SHOW COLUMNS FROM `transactions` LIKE 'bank_ofx_id'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `transactions` ADD COLUMN `bank_ofx_id` VARCHAR(255) DEFAULT NULL");
}


// Ensure unique constraint on bank FITID per account
$result = $db->query("SHOW INDEX FROM `transactions` WHERE Key_name = 'unique_bank_fitid'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `transactions` ADD UNIQUE KEY `unique_bank_fitid` (`account_id`,`bank_ofx_id`)");
}


// Remove the legacy core-field uniqueness rule. Different bank FITIDs can
// legitimately share a merchant, amount, date, description, and memo.
$result = $db->query("SHOW INDEX FROM `transactions` WHERE Key_name = 'unique_txn'");
if ($result->rowCount() > 0) {
    $db->exec("ALTER TABLE `transactions` DROP INDEX `unique_txn`");
}

$result = $db->query("SHOW INDEX FROM `transactions` WHERE Key_name = 'idx_transaction_fallback'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `transactions` ADD KEY `idx_transaction_fallback` (`account_id`,`date`,`amount`)");
}

// Ensure ledger balance columns exist in accounts
$result = $db->query("SHOW COLUMNS FROM `accounts` LIKE 'sort_code'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `accounts` ADD COLUMN `sort_code` VARCHAR(20) DEFAULT NULL");
}

$result = $db->query("SHOW COLUMNS FROM `accounts` LIKE 'account_number'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `accounts` ADD COLUMN `account_number` VARCHAR(50) DEFAULT NULL");
}

$result = $db->query("SHOW COLUMNS FROM `accounts` LIKE 'ledger_balance'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `accounts` ADD COLUMN `ledger_balance` DECIMAL(10,2) DEFAULT 0");
}

$result = $db->query("SHOW COLUMNS FROM `accounts` LIKE 'ledger_balance_date'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `accounts` ADD COLUMN `ledger_balance_date` DATE DEFAULT NULL");
}

$result = $db->query("SHOW COLUMNS FROM `accounts` LIKE 'closed'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `accounts` ADD COLUMN `closed` TINYINT NOT NULL DEFAULT 0");
}

$result = $db->query("SHOW COLUMNS FROM `accounts` LIKE 'closed_at'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `accounts` ADD COLUMN `closed_at` DATE DEFAULT NULL");
}

// Backfill only missing OFX IDs. Existing IDs remain stable so exports and
// repeat imports continue to recognise historical rows.
$txs = $db->query("SELECT id, account_id, date, amount, description, memo, ofx_type, bank_ofx_id FROM transactions WHERE ofx_id IS NULL OR ofx_id = ''");
$upd = $db->prepare('UPDATE transactions SET ofx_id = :oid WHERE id = :id');
while ($row = $txs->fetch(PDO::FETCH_ASSOC)) {
    $amountStr = number_format((float)$row['amount'], 2, '.', '');
    $normalise = function (string $text): string {
        $text = strtoupper(trim($text));
        return preg_replace('/\s+/', ' ', $text);
    };
    $normDesc = $normalise($row['description']);
    $ref = '';
    $chk = '';
    if (!empty($row['memo'])) {
        if (preg_match('/Ref:([^\s]+)/i', $row['memo'], $m)) {
            $ref = substr(trim($m[1]), 0, Transaction::REF_MAX_LENGTH);
        }
        if (preg_match('/Chk:([^\s]+)/i', $row['memo'], $m)) {
            $chk = substr(trim($m[1]), 0, Transaction::CHECK_MAX_LENGTH);
        }
    }
    if (!empty($row['bank_ofx_id'])) {
        $components = ['fitid', $row['account_id'], $row['bank_ofx_id']];
    } else {
        $components = ['fallback', $row['account_id'], $row['date'], $amountStr, $normDesc, trim((string)$row['memo']), (string)$row['ofx_type']];
        if ($ref !== '') { $components[] = $ref; }
        if ($chk !== '') { $components[] = $chk; }
    }
    $ofxId = sha1(implode('|', $components));
    try {
        $upd->execute(['oid' => $ofxId, 'id' => $row['id']]);
    } catch (PDOException $e) {
        // Leave ambiguous legacy duplicates unset for manual review.
    }
}

// Seed default segments and categories on a fresh database
$result = $db->query('SELECT COUNT(*) FROM segments');
if ($result->fetchColumn() == 0) {
    $defaultSegments = [
        [
            'name' => 'Fixed Commitments',
            'description' => 'Obligations that are unavoidable and cannot be changed in the short term without significant disruption or penalty. These represent your baseline cost of living.',
            'categories' => [
                ['name' => 'Mortgage / Rent', 'description' => 'Regular housing payments that provide your primary residence; contractual and immovable in the near term.'],
                ['name' => 'Utilities', 'description' => 'Gas, electricity, and water bills that supply essential household services; non-negotiable for daily living.'],
                ['name' => 'Council Tax', 'description' => 'Statutory local taxation payable to the council; mandatory with no discretion.'],
                ['name' => 'Insurance', 'description' => 'Home, car, health, and life policies that provide financial protection and are often legal or contractual necessities.'],
                ['name' => 'Telecoms', 'description' => 'Broadband and mobile contracts underpinning communication and work; typically fixed term with limited flexibility.'],
                ['name' => 'Subscriptions (Non-discretionary)', 'description' => 'Core statutory or quasi-statutory payments such as TV licences or mandatory service charges.'],
            ]
        ],
        [
            'name' => 'Essential Variables',
            'description' => 'Necessary costs that fluctuate and can be trimmed through management, yet remain core to maintaining normal living standards.',
            'categories' => [
                ['name' => 'Supermarkets / Groceries', 'description' => 'Food, drink, and household consumables; unavoidable but controllable through choices and planning.'],
                ['name' => 'Fuel / Transport', 'description' => 'Petrol, road tolls, rail tickets, and bus fares; essential for mobility, with scope to optimise routes and providers.'],
                ['name' => 'Healthcare', 'description' => 'Prescriptions, dentistry, opticians, and private GP visits; required for health maintenance with variable costs.'],
                ['name' => 'Childcare / School Costs', 'description' => 'Nursery fees, clubs, trips, and education-related charges; essential where applicable to family circumstances.'],
                ['name' => 'Essential Household', 'description' => 'Cleaning supplies, small repairs, and maintenance items necessary to keep the home functioning properly.'],
            ]
        ],
        [
            'name' => 'Discretionary Spend',
            'description' => 'Lifestyle choices that enhance quality of life but are not strictly necessary; reducible or removable under pressure.',
            'categories' => [
                ['name' => 'Restaurants / Pubs / Takeaways', 'description' => 'Spending on dining out, socialising, and convenience food; discretionary and easily curtailed.'],
                ['name' => 'Entertainment', 'description' => 'Streaming, cinema, theatre, concerts, and media; enriching but optional consumption.'],
                ['name' => 'Holidays / Travel', 'description' => 'Flights, hotels, and leisure trips; enjoyable, non-essential, and highly flexible to reduce.'],
                ['name' => 'Shopping', 'description' => 'Clothing, technology, homewares, and other non-essential retail purchases; often deferrable or avoidable.'],
                ['name' => 'Hobbies', 'description' => 'Personal interests such as books, gaming, photography, and sports equipment; fulfilling yet discretionary.'],
                ['name' => 'Subscriptions (Discretionary)', 'description' => 'Services like Netflix, Spotify, and gym memberships; cancellable without affecting essential living.'],
            ]
        ],
        [
            'name' => 'Financial Costs',
            'description' => 'Charges linked to borrowing, banking, and debt servicing that erode disposable income without adding utility.',
            'categories' => [
                ['name' => 'Loan Repayments', 'description' => 'Scheduled payments covering principal and interest on personal loans or other borrowings.'],
                ['name' => 'Credit Card Payments', 'description' => 'Repayments on outstanding balances; important to distinguish principal reduction from pure interest.'],
                ['name' => 'Overdraft Charges', 'description' => 'Fees for using overdraft facilities; generally expensive and best avoided.'],
                ['name' => 'Bank Fees', 'description' => 'Monthly account charges, penalty fees, and administrative costs imposed by the bank.'],
                ['name' => 'Interest Charges', 'description' => 'Additional costs on debt, including credit card interest, loan interest, and late payment penalties.'],
            ]
        ],
        [
            'name' => 'Income & Adjustments',
            'description' => 'All inflows and offsets, distinguishing true earnings from corrections and transfers to understand net position.',
            'categories' => [
                ['name' => 'Salary / Wages', 'description' => 'Regular employment income; predictable, recurring, and typically the largest inflow.'],
                ['name' => 'Benefits / Allowances', 'description' => 'State support such as child benefit, tax credits, or housing allowances contributing to essential income.'],
                ['name' => 'Refunds / Rebates', 'description' => 'Returned purchases, tax adjustments, and reimbursements; offsets prior spend rather than new earnings.'],
                ['name' => 'Transfers In', 'description' => 'Funds moved from other personal accounts; label clearly to avoid inflating income figures.'],
            ]
        ]
    ];

    $segStmt = $db->prepare('INSERT INTO segments (name, description) VALUES (:name, :description)');
    $catStmt = $db->prepare('INSERT INTO categories (name, description, segment_id) VALUES (:name, :description, :segment_id)');
    $linkStmt = $db->prepare('INSERT INTO segment_categories (segment_id, category_id) VALUES (:segment_id, :category_id)');


    foreach ($defaultSegments as $seg) {
        $segStmt->execute(['name' => $seg['name'], 'description' => $seg['description']]);
        $segmentId = (int)$db->lastInsertId();
        foreach ($seg['categories'] as $cat) {
            $catStmt->execute([
                'name' => $cat['name'],
                'description' => $cat['description'],
                'segment_id' => $segmentId
            ]);

            $categoryId = (int)$db->lastInsertId();
            $linkStmt->execute([
                'segment_id' => $segmentId,
                'category_id' => $categoryId
            ]);

        }
    }
}

echo "Database tables created.\n";
?>
