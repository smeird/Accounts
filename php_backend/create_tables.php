<?php
// Resets and creates all database tables used by the application.
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/models/Transaction.php';
require_once __DIR__ . '/models/Log.php';

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
            $ref = substr(trim($m[1]), 0, Transaction::REF_MAX_LENGTH);
        }
        if (preg_match('/Chk:([^\s]+)/i', $row['memo'], $m)) {
            $chk = substr(trim($m[1]), 0, Transaction::CHECK_MAX_LENGTH);
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
