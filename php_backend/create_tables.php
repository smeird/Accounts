<?php
// Resets and creates all database tables used by the application.
require_once __DIR__ . '/Database.php';

$db = Database::getConnection();

// Drop existing tables to ensure a clean state
$db->exec("SET FOREIGN_KEY_CHECKS=0");
$dropSql = <<<SQL
DROP TABLE IF EXISTS logs;
DROP TABLE IF EXISTS totp_secrets;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS transactions;
DROP TABLE IF EXISTS transaction_groups;
DROP TABLE IF EXISTS category_tags;
DROP TABLE IF EXISTS tags;
DROP TABLE IF EXISTS budgets;
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

CREATE TABLE IF NOT EXISTS totp_secrets (
    username VARCHAR(100) PRIMARY KEY,
    secret VARCHAR(64) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS settings (
    name VARCHAR(100) PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    sort_code VARCHAR(20) DEFAULT NULL,
    account_number VARCHAR(50) DEFAULT NULL,
    ledger_balance DECIMAL(10,2) DEFAULT 0,
    ledger_balance_date DATE DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS segments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT DEFAULT NULL,
    segment_id INT DEFAULT NULL,
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
    keyword VARCHAR(100) DEFAULT NULL,
    description TEXT DEFAULT NULL
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
    description TEXT DEFAULT NULL
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
    UNIQUE KEY unique_txn (account_id, date, amount, description(150), memo(150)),

    UNIQUE KEY unique_bank_fitid (account_id, bank_ofx_id),

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


// Ensure unique constraint on core transaction fields to prevent duplicates
$result = $db->query("SHOW INDEX FROM `transactions` WHERE Key_name = 'unique_txn'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `transactions` ADD UNIQUE KEY `unique_txn` (`account_id`,`date`,`amount`,`description`(150),`memo`(150))");
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

// Backfill synthetic OFX IDs using the extended scheme
$txs = $db->query('SELECT id, account_id, date, amount, description, memo, ofx_id FROM transactions');
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
            $ref = substr(trim($m[1]), 0, 32);
        }
        if (preg_match('/Chk:([^\s]+)/i', $row['memo'], $m)) {
            $chk = substr(trim($m[1]), 0, 20);
        }
    }
    // Legacy transactions lack raw STMTTRN blocks, so include an empty placeholder
    $components = [$row['account_id'], $row['date'], $amountStr, $normDesc, ''];
    if ($ref !== '') { $components[] = $ref; }
    if ($chk !== '') { $components[] = $chk; }
    $ofxId = sha1(implode('|', $components));
    if ($row['ofx_id'] !== $ofxId) {
        try {
            $upd->execute(['oid' => $ofxId, 'id' => $row['id']]);
        } catch (PDOException $e) {
            // Ignore duplicates
        }
    }
}

// Seed default segments and categories on a fresh database
$result = $db->query('SELECT COUNT(*) FROM segments');
if ($result->fetchColumn() == 0) {
    $defaultSegments = [
        [
            'name' => 'Fixed Commitments',
            'categories' => [
                ['name' => 'Housing & Utilities', 'description' => 'mortgage, rent, energy, water, council tax'],
                ['name' => 'Insurance & Protection', 'description' => 'home, car, health, life'],
                ['name' => 'Debt Obligations', 'description' => 'loans, credit repayments'],
                ['name' => 'Transport – Fixed', 'description' => 'car finance, season tickets, road tax'],
                ['name' => 'Essential Services', 'description' => 'broadband, mobile, TV licence'],
            ]
        ],
        [
            'name' => 'Semi-Flexible Essentials',
            'categories' => [
                ['name' => 'Food & Groceries', 'description' => 'supermarkets, essential shopping'],
                ['name' => 'Healthcare', 'description' => 'pharmacy, prescriptions, dental, opticians'],
                ['name' => 'Transport – Variable', 'description' => 'fuel, ad-hoc travel, taxis, parking'],
                ['name' => 'Education & Childcare', 'description' => 'school fees, childcare, training'],
            ]
        ],
        [
            'name' => 'Discretionary / Adjustable',
            'categories' => [
                ['name' => 'Leisure & Entertainment', 'description' => 'restaurants, cinema, streaming'],
                ['name' => 'Shopping & Lifestyle', 'description' => 'clothing, personal care, electronics'],
                ['name' => 'Travel & Holidays', 'description' => 'flights, hotels, excursions'],
                ['name' => 'Subscriptions & Memberships', 'description' => 'gyms, clubs, media, apps'],
                ['name' => 'Gifts & Celebrations', 'description' => 'birthdays, Christmas, special occasions'],
            ]
        ],
        [
            'name' => 'Future-Facing',
            'categories' => [
                ['name' => 'Savings & Investments', 'description' => 'ISAs, pensions, investments'],
                ['name' => 'Charity & Donations', 'description' => 'regular giving, one-off donations'],
                ['name' => 'Miscellaneous / Uncategorised', 'description' => 'catch-all, to be refined later'],
            ]
        ]
    ];

    $segStmt = $db->prepare('INSERT INTO segments (name, description) VALUES (:name, :description)');
    $catStmt = $db->prepare('INSERT INTO categories (name, description, segment_id) VALUES (:name, :description, :segment_id)');
    $linkStmt = $db->prepare('INSERT INTO segment_categories (segment_id, category_id) VALUES (:segment_id, :category_id)');


    foreach ($defaultSegments as $seg) {
        $segStmt->execute(['name' => $seg['name'], 'description' => null]);
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
