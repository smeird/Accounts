<?php
// Resets and creates all database tables used by the application.
require_once __DIR__ . '/Database.php';

$db = Database::getConnection();

// Drop existing tables to ensure a clean state
$db->exec("SET FOREIGN_KEY_CHECKS=0");
$dropSql = <<<SQL
DROP TABLE IF EXISTS logs;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS transactions;
DROP TABLE IF EXISTS transaction_groups;
DROP TABLE IF EXISTS category_tags;
DROP TABLE IF EXISTS tags;
DROP TABLE IF EXISTS budgets;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS segments;
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

echo "Database tables created.\n";
?>
