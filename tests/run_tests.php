<?php
require_once __DIR__ . '/../php_backend/models/User.php';
require_once __DIR__ . '/../php_backend/models/Tag.php';
require_once __DIR__ . '/../php_backend/models/Category.php';
require_once __DIR__ . '/../php_backend/models/Transaction.php';
require_once __DIR__ . '/../php_backend/models/Segment.php';
require_once __DIR__ . '/../php_backend/OfxParser.php';

// Use an in-memory SQLite database for tests.
putenv('DB_DSN=sqlite::memory:');
$db = Database::getConnection();

// Create minimal schema used by the models under test.
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, password TEXT);');
$db->exec('CREATE TABLE tags (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, keyword TEXT, description TEXT);');
$db->exec('CREATE TABLE segments (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, description TEXT);');
$db->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, description TEXT, segment_id INTEGER);');
$db->exec('CREATE TABLE category_tags (category_id INTEGER, tag_id INTEGER);');
$db->exec('CREATE TABLE transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, date TEXT, amount REAL, description TEXT, memo TEXT, category_id INTEGER, segment_id INTEGER, tag_id INTEGER, group_id INTEGER, transfer_id INTEGER);');
$db->exec('CREATE TABLE transaction_groups (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, description TEXT);');
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
$db->exec("INSERT INTO segments (name) VALUES ('Living')");
$segmentId = (int)$db->lastInsertId();
$catId = Category::create('Essentials', 'Essential spend', $segmentId);
$db->exec("INSERT INTO category_tags (category_id, tag_id) VALUES ($catId, $tagId)");
$cats = Category::allWithTags();
assertEqual('Essentials', $cats[0]['name'] ?? null, 'Category retrieved with tag');
assertEqual($tagId, $cats[0]['tags'][0]['id'] ?? null, 'Category has associated tag');
assertEqual($segmentId, $cats[0]['segment_id'] ?? null, 'Category segment id stored');
assertEqual('Living', $cats[0]['segment_name'] ?? null, 'Category segment name retrieved');

Category::update($catId, 'Essentials Updated', 'Updated desc', $segmentId);
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

// --- Segment tests ---
$db->exec('DELETE FROM segments');
$catId = Category::create('Food', 'Groceries');
$segId = Segment::create('Living', 'Living costs');
Segment::assignCategory($segId, $catId);
$segs = Segment::allWithCategories();
assertEqual('Living', $segs[0]['name'] ?? null, 'Segment retrieved with category');
assertEqual($catId, $segs[0]['categories'][0]['id'] ?? null, 'Segment linked to category');

Segment::update($segId, 'Living Updated', 'Updated desc');
$segs = Segment::allWithCategories();
assertEqual('Living Updated', $segs[0]['name'] ?? null, 'Segment updated');

$db->exec("INSERT INTO transactions (account_id, date, amount, description, category_id) VALUES (1, '2024-07-01', -20, 'Grocery run', $catId)");
$filtered = Transaction::filter($catId);
assertEqual(1, count($filtered), 'Transaction::filter returns one result for category');
assertEqual('Grocery run', $filtered[0]['description'] ?? null, 'Filtered transaction matches description');

$totals = Segment::totals();
assertEqual(-20.0, (float)$totals[0]['total'], 'Segment totals reflect transaction amount');

Segment::delete($segId);
$segCount = $db->query('SELECT COUNT(*) FROM segments')->fetchColumn();
assertEqual(0, (int)$segCount, 'Segment deleted');
$relCount = $db->query('SELECT COUNT(*) FROM categories WHERE segment_id IS NOT NULL')->fetchColumn();
assertEqual(0, (int)$relCount, 'Category-segment relation removed');

function assertThrows(callable $fn, string $message) {
    global $results;
    try {
        $fn();
        $results[] = "FAIL: $message (no exception)";
    } catch (Exception $e) {
        $results[] = "PASS: $message";
    }
}

$header = "OFXHEADER:100\nDATA:OFXSGML\nVERSION:102\nSECURITY:NONE\nENCODING:USASCII\nCHARSET:1252\nCOMPRESSION:NONE\nOLDFILEUID:NONE\nNEWFILEUID:NONE\n\n";
$accountBlock = "<BANKACCTFROM>\n<BANKID>123456\n<ACCTID>12345678\n<ACCTNAME>Test\n</BANKACCTFROM>\n";

$missingStmt = $header . "<OFX>\n$accountBlock<BANKTRANLIST>\n</BANKTRANLIST>\n</OFX>";
assertThrows(function() use ($missingStmt) { OfxParser::parse($missingStmt); }, 'Missing STMTTRN detected');

$missingDate = $header . "<OFX>\n$accountBlock<BANKTRANLIST>\n<STMTTRN>\n<TRNAMT>1.00\n</STMTTRN>\n</BANKTRANLIST>\n</OFX>";
assertThrows(function() use ($missingDate) { OfxParser::parse($missingDate); }, 'Missing DTPOSTED detected');

$missingAmt = $header . "<OFX>\n$accountBlock<BANKTRANLIST>\n<STMTTRN>\n<DTPOSTED>20240101\n</STMTTRN>\n</BANKTRANLIST>\n</OFX>";
assertThrows(function() use ($missingAmt) { OfxParser::parse($missingAmt); }, 'Missing TRNAMT detected');

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
