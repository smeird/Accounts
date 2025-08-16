<?php
require_once __DIR__ . '/../php_backend/models/User.php';
require_once __DIR__ . '/../php_backend/models/Tag.php';
require_once __DIR__ . '/../php_backend/models/Category.php';

// Use an in-memory SQLite database for tests.
putenv('DB_DSN=sqlite::memory:');
$db = Database::getConnection();

// Create minimal schema used by the models under test.
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, password TEXT);');
$db->exec('CREATE TABLE tags (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, keyword TEXT, description TEXT);');
$db->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, description TEXT);');
$db->exec('CREATE TABLE category_tags (category_id INTEGER, tag_id INTEGER);');
$db->exec('CREATE TABLE transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, description TEXT, account_id INTEGER, tag_id INTEGER, category_id INTEGER);');
$db->exec('CREATE TABLE budgets (id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER, amount REAL);');

$results = [];

function assertEqual($expected, $actual, string $message) {
    global $results;
    if ($expected === $actual) {
        $results[] = "PASS: $message";
    } else {
        $results[] = "FAIL: $message (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")";
    }
}

// Database driver should be sqlite
assertEqual('sqlite', $db->getAttribute(PDO::ATTR_DRIVER_NAME), 'Database driver is sqlite');

// Test user creation and retrieval
$userId = User::create('alice', 'secret');
assertEqual(1, $userId, 'User ID starts at 1');

$user = User::findByUsername('alice');
assertEqual('alice', $user['username'] ?? null, 'User retrieved by username');

// Test password verification
$reason = null;
$verifiedId = User::verify('alice', 'secret', $reason);
assertEqual(1, $verifiedId, 'Password verification succeeds');

$wrong = User::verify('alice', 'wrong', $reason);
assertEqual(null, $wrong, 'Password verification fails for wrong password');

// Test password update
User::updatePassword(1, 'newpass');
$updated = User::verify('alice', 'newpass', $reason);
assertEqual(1, $updated, 'Updated password verifies');

// --- Tag tests ---
$tagId = Tag::create('Food', 'supermarket', 'Groceries');
assertEqual(1, $tagId, 'Tag ID starts at 1');

$tags = Tag::all();
assertEqual('Food', $tags[0]['name'] ?? null, 'Tag retrieved by all()');

$match = Tag::findMatch('Visited the local supermarket yesterday');
assertEqual($tagId, $match, 'Keyword match finds tag');

$tag2 = Tag::create('Fuel', null, null);
Tag::setKeywordIfMissing($tag2, 'petrol');
$kw = $db->query('SELECT keyword FROM tags WHERE id = '.$tag2)->fetchColumn();
assertEqual('petrol', $kw, 'Keyword set when missing');

Tag::setKeywordIfMissing($tagId, 'grocery');
$kw1 = $db->query('SELECT keyword FROM tags WHERE id = '.$tagId)->fetchColumn();
assertEqual('supermarket', $kw1, 'Existing keyword not overwritten');

$db->exec("INSERT INTO transactions (description, account_id) VALUES ('Paid at supermarket', 1)");
$updatedCount = Tag::applyToAccountTransactions(1);
assertEqual(1, $updatedCount, 'applyToAccountTransactions updates one row');
$txTag = $db->query('SELECT tag_id FROM transactions WHERE id = 1')->fetchColumn();
assertEqual($tagId, (int)$txTag, 'Transaction tagged correctly');

// --- Category tests ---
$catId = Category::create('Essentials', 'Essential spend');
$db->exec("INSERT INTO category_tags (category_id, tag_id) VALUES ($catId, $tagId)");
$cats = Category::allWithTags();
assertEqual('Essentials', $cats[0]['name'] ?? null, 'Category retrieved with tag');
assertEqual($tagId, $cats[0]['tags'][0]['id'] ?? null, 'Category has associated tag');

Category::update($catId, 'Essentials Updated', 'Updated desc');
$cats = Category::allWithTags();
assertEqual('Essentials Updated', $cats[0]['name'] ?? null, 'Category updated');

$db->exec("UPDATE transactions SET category_id = $catId WHERE id = 1");
$db->exec("INSERT INTO budgets (category_id, amount) VALUES ($catId, 100)");
Category::delete($catId);
$catCount = $db->query('SELECT COUNT(*) FROM categories')->fetchColumn();
assertEqual(0, (int)$catCount, 'Category deleted');
$txCat = $db->query('SELECT category_id FROM transactions WHERE id = 1')->fetchColumn();
assertEqual(null, $txCat, 'Transaction category cleared');
$budCount = $db->query('SELECT COUNT(*) FROM budgets')->fetchColumn();
assertEqual(0, (int)$budCount, 'Budgets removed with category');

// Output results and set exit code
$failed = false;
foreach ($results as $line) {
    echo $line, "\n";
    if (strpos($line, 'FAIL') === 0) {
        $failed = true;
    }
}
if ($failed) {
    exit(1);
}
