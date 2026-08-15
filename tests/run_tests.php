<?php
require_once __DIR__ . '/../php_backend/models/User.php';
require_once __DIR__ . '/../php_backend/models/Tag.php';
require_once __DIR__ . '/../php_backend/models/Category.php';
require_once __DIR__ . '/../php_backend/models/CategoryTag.php';
require_once __DIR__ . '/../php_backend/models/Transaction.php';
require_once __DIR__ . '/../php_backend/models/Segment.php';
require_once __DIR__ . '/../php_backend/models/TransactionGroup.php';
require_once __DIR__ . '/../php_backend/models/SavedReport.php';
require_once __DIR__ . '/../php_backend/OfxParser.php';
require_once __DIR__ . '/../php_backend/NaturalLanguageReportParser.php';
require_once __DIR__ . '/../php_backend/models/TagAlias.php';
require_once __DIR__ . '/../php_backend/models/InstantDashboard.php';
require_once __DIR__ . '/../php_backend/models/YearlyDashboard.php';
require_once __DIR__ . '/../php_backend/models/GraphsDashboard.php';
require_once __DIR__ . '/../php_backend/models/ForecastDashboard.php';
require_once __DIR__ . '/../php_backend/models/Budget.php';
require_once __DIR__ . '/../php_backend/models/Project.php';
require_once __DIR__ . '/../php_backend/AiTaggingPipeline.php';
require_once __DIR__ . '/../php_backend/services/OfxImportService.php';
require_once __DIR__ . '/../php_backend/services/SchemaHealthService.php';

// Use an in-memory SQLite database for tests.
putenv('DB_DSN=sqlite::memory:');
$db = Database::getConnection();

// Create minimal schema used by the models under test.
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, password TEXT);');
$db->exec('CREATE TABLE accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, sort_code TEXT, account_number TEXT, ledger_balance REAL DEFAULT 0, ledger_balance_date TEXT);');
$db->exec('CREATE TABLE tags (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, name_normalized TEXT UNIQUE, keyword TEXT, description TEXT);');
$db->exec('CREATE TABLE tag_aliases (id INTEGER PRIMARY KEY AUTOINCREMENT, tag_id INTEGER, alias TEXT, alias_normalized TEXT, match_type TEXT, active TINYINT DEFAULT 1);');
$db->exec('CREATE TABLE segments (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, description TEXT);');
$db->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, description TEXT, segment_id INTEGER);');
$db->exec('CREATE TABLE category_tags (category_id INTEGER, tag_id INTEGER);');
$db->exec('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT);');
$db->exec('CREATE TABLE transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, date TEXT, amount REAL, description TEXT, memo TEXT, category_id INTEGER, segment_id INTEGER, tag_id INTEGER, group_id INTEGER, transfer_id INTEGER, ofx_id TEXT, ofx_type TEXT, bank_ofx_id TEXT);');
$db->exec('CREATE TABLE transaction_groups (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, description TEXT, active TINYINT DEFAULT 1);');
$db->exec('CREATE TABLE budgets (id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER, amount REAL);');
$db->exec('CREATE TABLE logs (id INTEGER PRIMARY KEY AUTOINCREMENT, level TEXT, message TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);');
$db->exec('CREATE TABLE saved_reports (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, description TEXT, filters TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);');
$db->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY AUTOINCREMENT, archived TINYINT DEFAULT 0, group_id INTEGER, benefit_financial REAL DEFAULT 0, weight_financial REAL DEFAULT 1, benefit_quality REAL DEFAULT 0, weight_quality REAL DEFAULT 1, benefit_risk REAL DEFAULT 0, weight_risk REAL DEFAULT 1, benefit_sustainability REAL DEFAULT 0, weight_sustainability REAL DEFAULT 1);');

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

// Masked credit card numbers should have masking removed
$maskedOfx = <<<OFX
<OFX>
<CREDITCARDMSGSRSV1>
<CCSTMTTRNRS>
<CCSTMTRS>
<CCACCTFROM><ACCTID>552213******8609</ACCTID></CCACCTFROM>
<BANKTRANLIST><STMTTRN><DTPOSTED>20240101</DTPOSTED><TRNAMT>-10.00</TRNAMT></STMTTRN></BANKTRANLIST>
</CCSTMTRS>
</CCSTMTTRNRS>
</CREDITCARDMSGSRSV1>
</OFX>
OFX;
$parsedMasked = OfxParser::parse($maskedOfx)['statements'][0];

assertEqual('552213******8609', $parsedMasked['account']->number, 'Masked account numbers retain placeholder digits');


// OFX streams without newlines between tags should still parse all transactions
$compactOfx = <<<OFX
<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS>
<BANKACCTFROM><BANKID>1<ACCTID>2</BANKACCTFROM>
<BANKTRANLIST><STMTTRN><DTPOSTED>20240101<TRNAMT>-1<FITID>1<NAME>A</STMTTRN><STMTTRN><DTPOSTED>20240102<TRNAMT>-2<FITID>2<NAME>B</STMTTRN></BANKTRANLIST>
</STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>
OFX;
$parsedCompact = OfxParser::parse($compactOfx)['statements'][0];
assertEqual(2, count($parsedCompact['transactions']), 'Parser handles tags without newlines');

// Profile-based normalisation and storage-safe field caps
$profileOfx = <<<OFX
<OFX>
<SIGNONMSGSRSV1><SONRS><FI><ORG>TESTBANK</ORG></FI></SONRS></SIGNONMSGSRSV1>
<BANKMSGSRSV1><STMTTRNRS><STMTRS>
<BANKACCTFROM><BANKID>1</BANKID><ACCTID>2</ACCTID></BANKACCTFROM>
<BANKTRANLIST><STMTTRN><DTPOSTED>20240101</DTPOSTED><TRNAMT>-1</TRNAMT><CHECKNUM>AB-12 34</CHECKNUM><REFNUM>ref-ABCDEFGHIJKLMNOPQRSTUVWXYZ</REFNUM><MEMO>Some memo that exceeds</MEMO><FITID>1</FITID></STMTTRN></BANKTRANLIST>
</STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>
OFX;
$parsedProfile = OfxParser::parse($profileOfx)['statements'][0];
$tx = $parsedProfile['transactions'][0];
assertEqual('1234', $tx->check, 'Profile regex removes non-digits from CHECKNUM');
assertEqual('REF-ABCDEFGHIJKLMNOPQRSTUVWXYZ', $tx->ref, 'Profile uppercases REFNUM without premature truncation');
assertEqual('Some memo that exceeds', $tx->memo, 'Parser preserves the complete transaction memo');
assertEqual('Some memo that exceeds', $tx->desc, 'Parser uses MEMO when NAME is absent');

$longFitidA = str_repeat('A', 40) . '1';
$longFitidB = str_repeat('A', 40) . '2';
$longFitidParsedA = OfxParser::parse(str_replace('<FITID>1</FITID>', '<FITID>' . $longFitidA . '</FITID>', $profileOfx))['statements'][0]['transactions'][0]->bankId;
$longFitidParsedB = OfxParser::parse(str_replace('<FITID>1</FITID>', '<FITID>' . $longFitidB . '</FITID>', $profileOfx))['statements'][0]['transactions'][0]->bankId;
assertEqual($longFitidA, $longFitidParsedA, 'Parser preserves a bank FITID up to the database limit');
assertEqual(false, $longFitidParsedA === $longFitidParsedB, 'Distinct long bank FITIDs remain distinct');

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

TagAlias::create($tagId, 'tesco', 'contains', true);
Tag::clearMatchCaches();
$aliasMatch = Tag::findMatch('TESCO SUPERSTORE 1234');
assertEqual($tagId, $aliasMatch, 'Alias contains match finds canonical tag');

for ($aliasNumber = 1; $aliasNumber <= 12; $aliasNumber++) {
    TagAlias::create($tagId, sprintf('pagination-sample-%02d', $aliasNumber));
}
$aliasPage = TagAlias::page(1, 10, 'pagination-sample', 'alias', 'desc');
assertEqual(12, $aliasPage['total'], 'Alias paging reports the filtered total');
assertEqual(2, $aliasPage['last_page'], 'Alias paging reports the final page');
assertEqual(10, count($aliasPage['data']), 'Alias paging limits the returned rows');
assertEqual('pagination-sample-12', $aliasPage['data'][0]['alias'] ?? null, 'Alias paging applies an allowlisted remote sort');
$aliasSecondPage = TagAlias::page(2, 10, 'pagination-sample');
assertEqual(2, count($aliasSecondPage['data']), 'Alias paging returns the remaining rows');
$literalAliasSearch = TagAlias::page(1, 10, '%');
assertEqual(0, $literalAliasSearch['total'], 'Alias search treats wildcard characters literally');
assertEqual(true, count(TagAlias::all()) >= 13, 'Legacy alias listing remains available');

$ctxRows = [
    ['tag_id' => $tagId, 'tag_name' => 'Food', 'alias' => 'Tesco'],
];
$ctx = AiTaggingPipeline::buildAliasAwareTagContext($ctxRows);
$resolvedAlias = AiTaggingPipeline::resolveCanonicalTag('Tesco', $ctx['canonicalByName'], $ctx['aliasToCanonical']);
assertEqual($tagId, $resolvedAlias['id'] ?? null, 'Model alias output resolves to canonical tag id');
$resolvedUnknown = AiTaggingPipeline::resolveCanonicalTag('Unknown Vendor', $ctx['canonicalByName'], $ctx['aliasToCanonical']);
assertEqual(null, $resolvedUnknown, 'Unknown alias does not resolve to canonical tag');

$contextWithAliaslessTag = AiTaggingPipeline::buildAliasAwareTagContext([
    ['tag_id' => $tagId, 'tag_name' => 'Food', 'alias' => null],
    ['tag_id' => 99, 'tag_name' => 'Household Bills', 'alias' => null],
]);
assertEqual(true, strpos($contextWithAliaslessTag['text'], 'Household Bills') !== false, 'AI context includes canonical tags that do not have aliases yet');

$tag2 = Tag::create('Fuel', null, null);
$fuelOptions = Tag::searchOptions('fuel', 10);
assertEqual($tag2, (int)($fuelOptions[0]['id'] ?? 0), 'Compact tag search returns the matching canonical tag');
assertEqual(['id', 'name'], array_keys($fuelOptions[0] ?? []), 'Compact tag search returns only picker fields');
$limitedTagOptions = Tag::searchOptions('', 2);
assertEqual(2, count($limitedTagOptions), 'Compact tag search respects its result limit');
$literalWildcardOptions = Tag::searchOptions('%', 10);
assertEqual(0, count($literalWildcardOptions), 'Compact tag search treats wildcard characters literally');
Tag::setKeywordIfMissing($tag2, 'petrol');
$kw = $db->query('SELECT keyword FROM tags WHERE id = '.$tag2)->fetchColumn();
assertEqual('petrol', $kw, 'Keyword set when missing');

Tag::setKeywordIfMissing($tagId, 'grocery');
$kw1 = $db->query('SELECT keyword FROM tags WHERE id = '.$tagId)->fetchColumn();
assertEqual('supermarket', $kw1, 'Existing keyword not overwritten');

$learnedFuelAlias = Tag::learnTransactionAlias($tag2, 'CARD PAYMENT SHELL SERVICE STATION 4837');
assertEqual('created', $learnedFuelAlias['status'] ?? null, 'A tagged transaction learns a reusable merchant alias');
$learnedFuelMatch = Tag::findMatch('CARD PAYMENT SHELL EXPRESS 9921');
assertEqual($tag2, $learnedFuelMatch, 'Learned merchant alias tags a similar transaction with a different reference');
$streamingTagId = Tag::create('Entertainment');
$memoAlias = Tag::learnTransactionAlias($streamingTagId, 'CARD PAYMENT', 'PAYPAL NETFLIX 883920');
assertEqual('netflix', $memoAlias['alias'] ?? null, 'Alias learning skips bank and payment-processor boilerplate and uses the memo merchant');
assertEqual($streamingTagId, Tag::findMatch('NETFLIX.COM 129334'), 'Memo-derived merchant alias is reusable');
$conflictingAlias = Tag::learnTransactionAlias($tagId, 'SHELL GARAGE 7711');
assertEqual('conflict', $conflictingAlias['status'] ?? null, 'Learned aliases are not silently reassigned to another tag');
assertEqual($tag2, Tag::findMatch('SHELL GARAGE 7711'), 'Existing canonical alias wins after a conflicting suggestion');
$tagCountBeforeCanonicalReuse = (int)$db->query('SELECT COUNT(*) FROM tags')->fetchColumn();
$reusedFuelTagId = Tag::create('  fuel  ');
assertEqual($tag2, $reusedFuelTagId, 'Normalized canonical names reuse an existing tag');
assertEqual($tagCountBeforeCanonicalReuse, (int)$db->query('SELECT COUNT(*) FROM tags')->fetchColumn(), 'Canonical reuse does not create a tag per transaction');

$db->exec("INSERT INTO transactions (description, account_id) VALUES ('Paid at supermarket', 1)");
$db->exec("INSERT INTO transactions (description, account_id) VALUES ('Supermarket account transfer', 1)");
$tagRuleTransferId = (int)$db->lastInsertId();
$db->exec("UPDATE transactions SET transfer_id = $tagRuleTransferId WHERE id = $tagRuleTransferId");
$updatedCount = Tag::applyToAccountTransactions(1);
assertEqual(1, $updatedCount, 'applyToAccountTransactions updates one row');
$txTag = $db->query('SELECT tag_id FROM transactions WHERE id = 1')->fetchColumn();
assertEqual($tagId, (int)$txTag, 'Transaction tagged correctly');
$transferRuleTag = $db->query("SELECT tag_id FROM transactions WHERE id = $tagRuleTransferId")->fetchColumn();
assertEqual(null, $transferRuleTag, 'Automatic tag rules leave confirmed transfers out of classification');

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

$assignmentTransactionId = Transaction::create(1, '2024-03-05', -45.0, 'Fuel assignment test', null, null, $tag2);
$assigned = CategoryTag::assign($catId, $tag2);
assertEqual($catId, $assigned['category_id'] ?? null, 'One-click assignment links a tag to a category');
$assignedCategory = $db->query("SELECT category_id FROM transactions WHERE id = $assignmentTransactionId")->fetchColumn();
assertEqual($catId, (int)$assignedCategory, 'One-click assignment updates existing tagged transactions');

$moveCategoryId = Category::create('Travel', 'Travel spend');
$moved = CategoryTag::assign($moveCategoryId, $tag2);
assertEqual($catId, $moved['previous_category_id'] ?? null, 'One-click assignment reports the previous category');
assertEqual($moveCategoryId, CategoryTag::getCategoryId($tag2), 'One-click assignment moves a tag atomically');
$movedCategory = $db->query("SELECT category_id FROM transactions WHERE id = $assignmentTransactionId")->fetchColumn();
assertEqual($moveCategoryId, (int)$movedCategory, 'Moving a tag updates existing transaction categories');

$unassigned = CategoryTag::assign(null, $tag2);
assertEqual(null, $unassigned['category_id'] ?? null, 'One-click assignment can leave a tag unassigned');
assertEqual(null, CategoryTag::getCategoryId($tag2), 'Unassigning removes the category-tag mapping');
$clearedCategory = $db->query("SELECT category_id FROM transactions WHERE id = $assignmentTransactionId")->fetchColumn();
assertEqual(null, $clearedCategory, 'Unassigning clears the category on existing tagged transactions');
$bulkAssigned = CategoryTag::assignMany($catId, [$tag2, $streamingTagId]);
assertEqual(2, count($bulkAssigned['tag_ids'] ?? []), 'Bulk category assignment saves several tags together');
assertEqual($catId, CategoryTag::getCategoryId($tag2), 'Bulk category assignment links the first selected tag');
assertEqual($catId, CategoryTag::getCategoryId($streamingTagId), 'Bulk category assignment links the second selected tag');
CategoryTag::assignMany(null, [$tag2, $streamingTagId]);
$db->exec("DELETE FROM transactions WHERE id = $assignmentTransactionId");
Category::delete($moveCategoryId);

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

// --- Group active flag ---
$groupId = TransactionGroup::create('Temp', 'desc', true);
TransactionGroup::update($groupId, 'Temp', 'desc', false);
$groups = TransactionGroup::all();
assertEqual(0, $groups[0]['active'], 'Group updated to inactive returns 0');

// --- Segment tests ---
$db->exec('DELETE FROM segments');
$catId = Category::create('Food', 'Groceries');
$segId = Segment::create('Living', 'Living costs');
$db->exec("INSERT INTO transactions (account_id, date, amount, description, category_id) VALUES (1, '2024-07-01', -20, 'Grocery run', $catId)");
$segmentAssignment = Segment::assignCategories($segId, [$catId]);
assertEqual(null, $segmentAssignment['assignments'][0]['previous_segment_id'] ?? null, 'One-click segment assignment reports an unassigned category');
assertEqual(1, $segmentAssignment['updated_transactions'] ?? 0, 'One-click segment assignment updates existing categorised transactions');
$segs = Segment::allWithCategories();
assertEqual('Living', $segs[0]['name'] ?? null, 'Segment retrieved with category');
assertEqual($catId, $segs[0]['categories'][0]['id'] ?? null, 'Segment linked to category');
$txSegment = $db->query("SELECT segment_id FROM transactions WHERE description = 'Grocery run'")->fetchColumn();
assertEqual($segId, (int)$txSegment, 'One-click segment assignment propagates to existing transactions');

$otherSegId = Segment::create('Lifestyle', 'Optional spending');
$segmentMove = Segment::assignCategories($otherSegId, [$catId]);
assertEqual($segId, $segmentMove['assignments'][0]['previous_segment_id'] ?? null, 'Moving a category reports its previous segment');
$txSegment = $db->query("SELECT segment_id FROM transactions WHERE description = 'Grocery run'")->fetchColumn();
assertEqual($otherSegId, (int)$txSegment, 'Moving a category updates existing transaction segments atomically');

Segment::assignCategories(null, [$catId]);
$catSegment = $db->query("SELECT segment_id FROM categories WHERE id = $catId")->fetchColumn();
assertEqual(null, $catSegment, 'Removing a category clears its segment link');
$txSegment = $db->query("SELECT segment_id FROM transactions WHERE description = 'Grocery run'")->fetchColumn();
assertEqual(null, $txSegment, 'Removing a category clears existing transaction segments');
Segment::delete($otherSegId);
Segment::assignCategories($segId, [$catId]);

Segment::update($segId, 'Living Updated', 'Updated desc');
$segs = Segment::allWithCategories();
assertEqual('Living Updated', $segs[0]['name'] ?? null, 'Segment updated');

$filtered = Transaction::filter($catId);
assertEqual(1, count($filtered), 'Transaction::filter returns one result for category');
assertEqual('Grocery run', $filtered[0]['description'] ?? null, 'Filtered transaction matches description');

$catId2 = Category::create('Bills', 'Utilities');
$db->exec("INSERT INTO transactions (account_id, date, amount, description, category_id) VALUES (1, '2024-07-02', -30, 'Electric', $catId2)");
$bulkSegmentAssignment = Segment::assignCategories($segId, [$catId, $catId2]);
assertEqual(2, count($bulkSegmentAssignment['category_ids'] ?? []), 'Bulk segment assignment saves several categories together');
$bulkSegmentCount = $db->query("SELECT COUNT(*) FROM categories WHERE id IN ($catId, $catId2) AND segment_id = $segId")->fetchColumn();
assertEqual(2, (int)$bulkSegmentCount, 'Bulk segment assignment links every selected category');
$bulkTransactionCount = $db->query("SELECT COUNT(*) FROM transactions WHERE category_id IN ($catId, $catId2) AND segment_id = $segId")->fetchColumn();
assertEqual(2, (int)$bulkTransactionCount, 'Bulk segment assignment propagates to matching transactions');
$multi = Transaction::filter([$catId, $catId2]);
assertEqual(2, count($multi), 'Transaction::filter supports multiple categories');


$totals = Segment::totals();
assertEqual(-50.0, (float)$totals[0]['total'], 'Segment totals reflect transaction amount');

Segment::delete($segId);
$segCount = $db->query('SELECT COUNT(*) FROM segments')->fetchColumn();
assertEqual(0, (int)$segCount, 'Segment deleted');
$relCount = $db->query('SELECT COUNT(*) FROM categories WHERE segment_id IS NOT NULL')->fetchColumn();
assertEqual(0, (int)$relCount, 'Category-segment relation removed');

$db->exec("INSERT INTO transactions (account_id, date, amount, description, memo) VALUES (1, '2024-07-03', -5, 'Snack', 'afternoon tea')");
$memoFiltered = Transaction::filter(null, null, null, null, null, 'tea');
assertEqual(1, count($memoFiltered), 'Transaction::filter filters by memo');

// --- Recurring income/outgoing detection ---
$db->exec('DELETE FROM transactions');

$now = time();
$u1 = date('Y-m-15', strtotime('-3 months', $now));
$u2 = date('Y-m-15', strtotime('-2 months', $now));
$u3 = date('Y-m-15', strtotime('-1 month', $now));
$e1 = date('Y-m-25', strtotime('-3 months', $now));
$e2 = date('Y-m-25', strtotime('-2 months', $now));
$e3 = date('Y-m-25', strtotime('-1 month', $now));
$old1 = date('Y-m-d', strtotime('-7 months', $now));
$old2 = date('Y-m-d', strtotime('-6 months', $now));
$latestUtility = date('Y-m-15', $now);

$db->exec("INSERT INTO transactions (account_id, date, amount, description) VALUES
    (1, '$u1', -100, 'Utility Co'),
    (1, '$u2', -110, 'Utility Co'),
    (1, '$u3', -90, 'Utility Co'),
    (1, '$e1', 2000, 'Employer'),
    (1, '$e2', 2100, 'Employer'),
    (1, '$e3', 2200, 'Employer'),
    (1, '$old1', -30, 'OldService'),
    (1, '$old2', -35, 'OldService'),
    (1, '$latestUtility', -999, 'Utility Co')

");
$latestUtilityId = (int)$db->lastInsertId();
$db->exec("UPDATE transactions SET transfer_id = $latestUtilityId WHERE id = $latestUtilityId");
$recSpend = Transaction::getRecurringSpend(false);
$recIncome = Transaction::getRecurringSpend(true);
assertEqual(1, count($recSpend), 'Recurring spend detected');
assertEqual(1, count($recIncome), 'Recurring income detected');
assertEqual(15, (int)$recSpend[0]['day'], 'Recurring spend day matched');
assertEqual(25, (int)$recIncome[0]['day'], 'Recurring income day matched');
assertEqual(300.0, (float)$recSpend[0]['total'], 'Recurring spend total summed');
assertEqual(6300.0, (float)$recIncome[0]['total'], 'Recurring income total summed');
assertEqual(90.0, (float)$recSpend[0]['last_amount'], 'Recurring spend last amount stored');
assertEqual(2200.0, (float)$recIncome[0]['last_amount'], 'Recurring income last amount stored');
$db->exec('DELETE FROM transactions');

$januaryTransactionId = Transaction::create(1, '2024-01-31', -12.50, 'January boundary test');
$februaryTransactionId = Transaction::create(1, '2024-02-01', -15.00, 'February boundary test');
$januaryRows = Transaction::getByMonth(1, 2024);
assertEqual([$januaryTransactionId], array_map('intval', array_column($januaryRows, 'id')), 'Monthly statement query uses an exact date range');
$invalidStatementMonthRejected = false;
try {
    Transaction::getByMonth(13, 2024);
} catch (InvalidArgumentException $e) {
    $invalidStatementMonthRejected = true;
}
assertEqual(true, $invalidStatementMonthRejected, 'Monthly statement rejects an invalid month');
$db->exec("DELETE FROM transactions WHERE id IN ($januaryTransactionId, $februaryTransactionId)");

// --- Duplicate FITID test ---
$first = Transaction::create(1, '2024-08-01', 10, 'First', null, null, null, null, 'ofx1', 'DEBIT', 'DUP123');
assertEqual(true, $first > 0, 'Initial transaction inserted');
$second = Transaction::create(1, '2024-08-02', 20, 'Second', null, null, null, null, 'ofx2', 'DEBIT', 'DUP123');
assertEqual(0, $second, 'Duplicate FITID skipped');
$count = $db->query('SELECT COUNT(*) FROM transactions WHERE bank_ofx_id IS NOT NULL')->fetchColumn();
assertEqual(1, (int)$count, 'Only one transaction stored after duplicate FITID');
$logCount = $db->query("SELECT COUNT(*) FROM logs WHERE level = 'WARNING'")->fetchColumn();
assertEqual(1, (int)$logCount, 'Duplicate FITID conflict logged');

// Exact duplicate with same details should be skipped without logging
$dupSame1 = Transaction::create(1, '2024-08-03', 30, 'Third', null, null, null, null, 'ofx3', 'DEBIT', 'SAME123');
assertEqual(true, $dupSame1 > 0, 'Baseline transaction inserted');
$dupSame2 = Transaction::create(1, '2024-08-03', 30, 'Third', null, null, null, null, 'ofx4', 'DEBIT', 'SAME123');
assertEqual(0, $dupSame2, 'Exact duplicate FITID skipped');
$logCountSame = $db->query("SELECT COUNT(*) FROM logs WHERE level = 'WARNING'")->fetchColumn();
assertEqual(1, (int)$logCountSame, 'Exact duplicate not logged again');

// Surrogate ID generation when FITID is missing
$surrogate = sha1('1|2024-08-04|40|SURR');
$sur1 = Transaction::create(1, '2024-08-04', 40, 'SURR', null, null, null, null, $surrogate, 'DEBIT', $surrogate);
assertEqual(true, $sur1 > 0, 'Surrogate transaction inserted');
$sur2 = Transaction::create(1, '2024-08-04', 40, 'SURR', null, null, null, null, $surrogate, 'DEBIT', $surrogate);
assertEqual(0, $sur2, 'Surrogate ID prevents duplicate');

// Distinct bank identities must survive matching merchant, date, and amount fields.
$sameCoreA = Transaction::create(1, '2024-08-05', 50, 'Repeated purchase', 'First', null, null, null, sha1('same-core-a'), 'DEBIT', 'CORE-A');
$sameCoreB = Transaction::create(1, '2024-08-05', 50, 'Repeated purchase', 'First', null, null, null, sha1('same-core-b'), 'DEBIT', 'CORE-B');
assertEqual(true, $sameCoreA > 0 && $sameCoreB > 0, 'Different bank FITIDs preserve same-day matching purchases');

// Similar transactions on nearby days are not silently collapsed.
$pending = Transaction::create(1, '2024-08-05', 50, 'PendingTx', null, null, null, null, sha1('p1'), 'DEBIT', 'PEN1');
assertEqual(true, $pending > 0, 'Pending transaction inserted');
$posted = Transaction::create(1, '2024-08-06', 50, 'PendingTx', null, null, null, null, sha1('p2'), 'DEBIT', 'POS1');
assertEqual(true, $posted > 0, 'Different bank FITIDs on nearby days remain distinct');

$fallbackA = Transaction::create(1, '2024-08-07', 15, 'No bank ID', 'Memo A');
$fallbackDuplicate = Transaction::create(1, '2024-08-07', 15, 'No bank ID', 'Memo A');
$fallbackDifferentMemo = Transaction::create(1, '2024-08-07', 15, 'No bank ID', 'Memo B');
assertEqual(0, $fallbackDuplicate, 'Identifier-less exact duplicate is skipped');
assertEqual(true, $fallbackDifferentMemo > 0, 'Identifier-less transaction with a different memo is preserved');

$finalLog = $db->query("SELECT COUNT(*) FROM logs WHERE level = 'WARNING'")->fetchColumn();
assertEqual(1, (int)$finalLog, 'No extra warnings from exact duplicates or pending collapse');

// --- Transfer detection and linking ---
$db->exec("INSERT INTO accounts (name) VALUES ('Checking'), ('Savings')");
$db->exec("INSERT INTO transactions (account_id, date, amount, description) VALUES (1, '2024-09-01', -50, 'Transfer out'), (2, '2024-09-01', 50, 'Transfer in')");
$candidates = Transaction::getTransferCandidates();
assertEqual(1, count($candidates), 'Transfer candidate detected');
assertEqual('Checking', $candidates[0]['from_account'] ?? null, 'Candidate from account matches');
assertEqual('Savings', $candidates[0]['to_account'] ?? null, 'Candidate to account matches');
Transaction::linkTransfer($candidates[0]['from_id'], $candidates[0]['to_id']);
$linked = Transaction::getTransfers();
assertEqual(1, count($linked), 'Linked transfer returned');
assertEqual(-50.0, (float)$linked[0]['from_amount'], 'Linked from amount stored');
$candidatesAfter = Transaction::getTransferCandidates();
assertEqual(0, count($candidatesAfter), 'No candidates after linking');

// Auto-link transfer creation should support differing descriptions with opposite values
$autoOutId = Transaction::create(1, '2024-09-04', -75.00, 'Current acc transfer out');
$autoInId = Transaction::create(2, '2024-09-04', 75.00, 'Savings transfer in');
$autoTransferIds = $db->query("SELECT transfer_id FROM transactions WHERE id IN ($autoOutId, $autoInId) ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
assertEqual(true, !empty($autoTransferIds[0]) && $autoTransferIds[0] == $autoTransferIds[1], 'Auto transfer linking matches equal and opposite amounts despite different descriptions');

// Bank transfer legs can settle on different dates and OFX may mark the first leg
// as XFER before the matching account is imported. The first leg is not excluded
// from spending until the corresponding arrival is present.
$delayedOutId = Transaction::create(1, '2024-09-10', -125.00, 'Online transfer to savings', null, null, null, null, null, 'XFER');
$unpairedOfxTransferId = $db->query("SELECT transfer_id FROM transactions WHERE id = $delayedOutId")->fetchColumn();
assertEqual(null, $unpairedOfxTransferId, 'A one-sided OFX transfer is not excluded before its counterpart exists');
$delayedInId = Transaction::create(2, '2024-09-12', 125.00, 'Transfer received from current', null, null, null, null, null, 'XFER');
$delayedTransferIds = $db->query("SELECT transfer_id FROM transactions WHERE id IN ($delayedOutId, $delayedInId) ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
assertEqual(true, !empty($delayedTransferIds[0]) && $delayedTransferIds[0] == $delayedTransferIds[1], 'OFX transfer legs settle within three days and link regardless of import order');

$protectedOutId = Transaction::create(1, '2024-09-13', -90.00, 'Transfer to reserve');
$protectedInId = Transaction::create(2, '2024-09-13', 90.00, 'Transfer from current');
$thirdLegId = Transaction::create(3, '2024-09-13', 90.00, 'Transfer received elsewhere');
$thirdLegTransferId = $db->query("SELECT transfer_id FROM transactions WHERE id = $thirdLegId")->fetchColumn();
assertEqual(null, $thirdLegTransferId, 'A completed transfer pair cannot absorb an unrelated third leg');

// Similar values across accounts should not be linked across dates without a
// transfer signal in either description or OFX type.
$unrelatedOutId = Transaction::create(1, '2024-09-15', -20.00, 'Coffee shop purchase');
$unrelatedInId = Transaction::create(2, '2024-09-16', 20.00, 'Promotional cashback');
$unrelatedTransferIds = $db->query("SELECT transfer_id FROM transactions WHERE id IN ($unrelatedOutId, $unrelatedInId) ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
assertEqual([null, null], $unrelatedTransferIds, 'Different-day equal values without transfer evidence remain ordinary transactions');

$untaggedBeforeTransfer = Transaction::getUntaggedTotal();
$transferTagOutId = Transaction::create(1, '2024-09-18', -65.00, 'Internal transfer to savings');
$transferTagInId = Transaction::create(2, '2024-09-18', 65.00, 'Internal transfer from current');
$untaggedAfterTransfer = Transaction::getUntaggedTotal();
assertEqual($untaggedBeforeTransfer, $untaggedAfterTransfer, 'Linked transfers do not inflate the untagged transaction count');

// Transfer candidate matching should tolerate minor float representation differences
$db->exec("INSERT INTO transactions (account_id, date, amount, description) VALUES (1, '2024-09-05', -33.335, 'Rounding out'), (2, '2024-09-05', 33.3349, 'Rounding in')");
$roundedCandidates = Transaction::getTransferCandidates();
$roundedMatch = array_filter($roundedCandidates, function ($c) {
    return $c['date'] === '2024-09-05';
});
assertEqual(1, count($roundedMatch), 'Transfer candidate detection allows equivalent opposite values to currency precision');

// Same-account transactions cannot be manually linked as transfers
$db->exec("INSERT INTO transactions (account_id, date, amount, description) VALUES (1, '2024-09-02', -25, 'Internal move'), (1, '2024-09-02', 25, 'Internal move')");
$sameAccountIds = $db->query("SELECT id FROM transactions WHERE date = '2024-09-02' ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
$sameAccountLinked = Transaction::linkTransfer((int)$sameAccountIds[0], (int)$sameAccountIds[1]);
assertEqual(false, $sameAccountLinked, 'Manual transfer linking rejects same-account pair');
$sameAccountTransferCount = (int)$db->query("SELECT COUNT(*) FROM transactions WHERE id IN ({$sameAccountIds[0]}, {$sameAccountIds[1]}) AND transfer_id IS NOT NULL")->fetchColumn();
assertEqual(0, $sameAccountTransferCount, 'Rejected same-account pair remains unlinked');

// Zero-value bookkeeping rows are not account transfers, even across accounts.
$db->exec("INSERT INTO transactions (account_id, date, amount, description) VALUES (1, '2024-09-02', 0, 'Balance marker'), (2, '2024-09-02', 0, 'Balance marker')");
$zeroValueIds = $db->query("SELECT id FROM transactions WHERE description = 'Balance marker' ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
$zeroValueLinked = Transaction::linkTransfer((int)$zeroValueIds[0], (int)$zeroValueIds[1]);
assertEqual(false, $zeroValueLinked, 'Manual transfer linking rejects zero-value bookkeeping rows');


// Transactions already linked to a different transfer cannot be relinked
$db->exec("INSERT INTO transactions (account_id, date, amount, description) VALUES (1, '2024-09-03', -40, 'Move out A'), (2, '2024-09-03', -40, 'Move out B'), (3, '2024-09-03', 40, 'Move in')");
$relinkIds = $db->query("SELECT id FROM transactions WHERE date = '2024-09-03' ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
$initialLink = Transaction::linkTransfer((int)$relinkIds[1], (int)$relinkIds[2]);
assertEqual(true, $initialLink, 'Initial inter-account manual link succeeds');
$relinkAttempt = Transaction::linkTransfer((int)$relinkIds[0], (int)$relinkIds[2]);
assertEqual(false, $relinkAttempt, 'Manual transfer linking rejects relinking to different transfer');
$originalTransferId = $db->query("SELECT transfer_id FROM transactions WHERE id = {$relinkIds[2]}")->fetchColumn();
assertEqual((int)$relinkIds[1], (int)$originalTransferId, 'Original transfer link remains deterministic after failed relink');

$db->exec("INSERT INTO projects (archived, group_id) VALUES (0, 777)");
$db->exec("INSERT INTO transactions (account_id, date, amount, description, group_id) VALUES (1, '2024-09-20', -100, 'Project purchase', 777)");
$db->exec("INSERT INTO transactions (account_id, date, amount, description, group_id, transfer_id) VALUES (1, '2024-09-21', -200, 'Project account transfer', 777, 9001)");
$projectRows = Project::all(false);
assertEqual(100.0, (float)($projectRows[0]['spent'] ?? 0), 'Project spending excludes internal account transfers');

// --- Link preview test ---
$sample = 'file://' . realpath(__DIR__ . '/../sample_data/link_preview.html');
$_GET['url'] = $sample;
ob_start();
include __DIR__ . '/../php_backend/public/link_preview.php';
$previewJson = ob_get_clean();
$previewData = json_decode($previewJson, true);
assertEqual('OG Sample Title', $previewData['title'] ?? null, 'Link preview returns og:title');
assertEqual('OG Sample Description', $previewData['description'] ?? null, 'Link preview returns og:description');


// --- Natural language report parser ---
$db->exec('DELETE FROM categories');
$db->exec("INSERT INTO categories (name) VALUES ('cars')");
$carId = (int)$db->lastInsertId();
$parsed = NaturalLanguageReportParser::parse('costs for cars in the last 12 months');
assertEqual(null, $parsed['category'], 'Natural language parser ignores category');
assertEqual(date('Y-m-d', strtotime('-12 months')), $parsed['start'], 'Natural language parser sets start date');

$db->exec('DELETE FROM tags');
$db->exec('DELETE FROM sqlite_sequence WHERE name="tags"');
$db->exec("INSERT INTO tags (name, keyword, description) VALUES ('car','', ''), ('auto','', '')");
$parsedTags = NaturalLanguageReportParser::parse('car auto');
sort($parsedTags['tag']);
assertEqual([1,2], $parsedTags['tag'], 'Natural language parser finds multiple tags');

$db->exec('DELETE FROM categories');
$db->exec('DELETE FROM sqlite_sequence WHERE name="categories"');
$db->exec("INSERT INTO categories (name) VALUES ('#groceries')");
$catSymbolId = (int)$db->lastInsertId();
$parsedPunctCat = NaturalLanguageReportParser::parse('#groceries');
assertEqual(null, $parsedPunctCat['category'], 'Natural language parser ignores category starting with symbol');

$db->exec('DELETE FROM tags');
$db->exec('DELETE FROM sqlite_sequence WHERE name="tags"');
$db->exec("INSERT INTO tags (name, keyword, description) VALUES ('#fun','', ''), ('bills!','', '')");
$parsedPunctTags = NaturalLanguageReportParser::parse('#fun bills!');
sort($parsedPunctTags['tag']);
assertEqual([1,2], $parsedPunctTags['tag'], 'Natural language parser handles tag names with symbols');


$repId = SavedReport::create('Sample', 'Example', ['category' => [1]]);
$reports = SavedReport::all();
assertEqual('Sample', $reports[0]['name'] ?? null, 'SavedReport::create stores report');
assertEqual('Example', $reports[0]['description'] ?? null, 'SavedReport description stored');
SavedReport::delete($repId);
$afterDel = SavedReport::all();
assertEqual(0, count($afterDel), 'SavedReport::delete removes report');

// --- Instant dashboard snapshot ---
$db->exec('ALTER TABLE budgets ADD COLUMN month INTEGER');
$db->exec('ALTER TABLE budgets ADD COLUMN year INTEGER');
$db->exec('DELETE FROM transactions');
$db->exec('DELETE FROM budgets');
$db->exec('DELETE FROM categories');
$db->exec('DELETE FROM tags');
$db->exec('DELETE FROM accounts');
$db->exec("INSERT INTO accounts (id, name, ledger_balance, ledger_balance_date) VALUES (1, 'Current', 2500, '2026-08-10'), (2, 'Savings', 7500, '2026-08-09')");
$db->exec("INSERT INTO categories (id, name) VALUES (1, 'Income'), (2, 'Home')");
$db->exec("INSERT INTO tags (id, name, name_normalized) VALUES (1, 'Salary', 'salary'), (2, 'Bills', 'bills')");
$db->exec("INSERT INTO budgets (category_id, amount, month, year) VALUES (2, 1000, 8, 2026)");
$db->exec("INSERT INTO transactions (account_id, date, amount, description, category_id, tag_id) VALUES
    (1, '2026-07-25', 3000, 'July salary', 1, 1),
    (1, '2026-07-02', -1200, 'July home costs', 2, 2),
    (1, '2026-08-01', 3200, 'August salary', 1, 1),
    (1, '2026-08-02', -850, 'August home costs', 2, 2)");
$db->exec("INSERT INTO transactions (account_id, date, amount, description, transfer_id) VALUES
    (1, '2026-08-03', -500, 'Transfer out', 99),
    (2, '2026-08-03', 500, 'Transfer in', 99)");
$instant = InstantDashboard::getSnapshot(new DateTimeImmutable('2026-08-10T12:00:00+01:00'));
assertEqual(10000.0, (float)$instant['headline']['balance'], 'Instant dashboard totals account balances');
assertEqual(3200.0, (float)$instant['metrics']['income'], 'Instant dashboard reports monthly income');
assertEqual(850.0, (float)$instant['metrics']['spending'], 'Instant dashboard excludes transfers from spending');
assertEqual(2350.0, (float)$instant['metrics']['cashflow'], 'Instant dashboard calculates monthly cash flow');
assertEqual(85.0, (float)$instant['budget']['used'], 'Instant dashboard calculates budget pressure');
assertEqual(true, (bool)$instant['recent'][0]['is_transfer'], 'Instant dashboard keeps transfers in recent activity');
assertEqual(0, (int)$instant['data_quality']['untagged_transactions'], 'Instant dashboard excludes transfers from untagged attention counts');
assertEqual(6, count($instant['trend']), 'Instant dashboard returns a six-month trend');

// --- Yearly dashboard snapshot and portable monthly budgets ---
$db->exec("INSERT INTO transactions (account_id, date, amount, description, category_id, tag_id) VALUES
    (1, '2025-07-25', 2800, 'Prior July salary', 1, 1),
    (1, '2025-07-02', -1100, 'Prior July home costs', 2, 2),
    (1, '2025-08-01', 3000, 'Prior August salary', 1, 1),
    (1, '2025-08-02', -800, 'Prior August home costs', 2, 2)");
$yearly = YearlyDashboard::getSnapshot(2026);
assertEqual(6200.0, (float)$yearly['metrics']['income'], 'Yearly dashboard totals annual income');
assertEqual(2050.0, (float)$yearly['metrics']['spending'], 'Yearly dashboard totals annual spending');
assertEqual(4150.0, (float)$yearly['metrics']['cashflow'], 'Yearly dashboard calculates annual cash flow');
assertEqual(2, (int)$yearly['metrics']['active_months'], 'Yearly dashboard counts active months');
assertEqual('Home', $yearly['top_categories'][0]['name'] ?? null, 'Yearly dashboard ranks spending categories');
assertEqual(2350.0, (float)$yearly['months'][7]['cashflow'], 'Yearly dashboard returns August cash flow');
assertEqual(6.9, (float)$yearly['comparison']['income'], 'Yearly dashboard compares year-to-date with the equivalent prior-year period');
$db->exec("INSERT INTO segments (id, name) VALUES (10, 'Household')");
$db->exec("UPDATE categories SET segment_id = 10 WHERE id = 2");
$graphs = GraphsDashboard::getSnapshot(2026);
assertEqual(10000.0, (float)$graphs['metrics']['balance'], 'Graphs dashboard reports the current net account position');
assertEqual(2050.0, (float)$graphs['metrics']['spending'], 'Graphs dashboard reconciles annual spending');
assertEqual('Home', $graphs['categories'][0]['name'] ?? null, 'Graphs dashboard ranks category drivers');
assertEqual(1200.0, (float)($graphs['categories'][0]['months'][6]['amount'] ?? 0), 'Graphs dashboard builds a category-by-month pattern');
assertEqual('Household', $graphs['segments'][0]['name'] ?? null, 'Graphs dashboard rolls spending into segments');
assertEqual('Bills', $graphs['tags'][0]['name'] ?? null, 'Graphs dashboard ranks reusable tag patterns');
assertEqual(2, count($graphs['accounts']), 'Graphs dashboard includes account balance context');
assertEqual(4150.0, (float)$graphs['months'][7]['cumulative_cashflow'], 'Graphs dashboard calculates cumulative cash flow');

// --- Transaction-backed 12-month forecast ---
$forecastHistoryRows = [];
foreach (['2025-09', '2025-10', '2025-11', '2025-12', '2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06'] as $forecastMonth) {
    $forecastHistoryRows[] = "(1, '$forecastMonth-01', 3000, 'Regular income', 1, 1)";
    $forecastHistoryRows[] = "(1, '$forecastMonth-08', -1000, 'Regular home costs', 2, 2)";
}
$db->exec('INSERT INTO transactions (account_id, date, amount, description, category_id, tag_id) VALUES ' . implode(',', $forecastHistoryRows));
$ignoreTagId = Tag::getIgnoreId();
$db->exec("INSERT INTO transactions (account_id, date, amount, description, category_id, tag_id) VALUES (1, '2026-08-05', -9999, 'Ignored outlier', 2, $ignoreTagId)");
$forecast = ForecastDashboard::getSnapshot(new DateTimeImmutable('2026-08-15T12:00:00+01:00'));
assertEqual(true, (bool)$forecast['has_data'], 'Forecast reports usable transaction history');
assertEqual(12, count($forecast['forecast']), 'Forecast returns twelve planning periods');
assertEqual(true, (bool)$forecast['forecast'][0]['partial'], 'Forecast treats the first month as the remaining partial period');
assertEqual(0.6774, (float)$forecast['forecast'][0]['factor'], 'Forecast anchors its first period after the latest ledger balance date');
assertEqual(13, (int)$forecast['coverage']['active_months'], 'Forecast counts complete active history months');
assertEqual(28, (int)$forecast['coverage']['transaction_count'], 'Forecast excludes confirmed transfers and IGNORE-tagged activity');
assertEqual('low', $forecast['coverage']['confidence'], 'Forecast labels limited transaction coverage conservatively');
assertEqual('Home', $forecast['top_categories'][0]['name'] ?? null, 'Forecast ranks projected spending drivers');
assertEqual(12000.0, (float)($forecast['top_categories'][0]['historical_amount'] ?? 0), 'Forecast spending drivers use the latest twelve complete active months');
assertEqual((float)$forecast['metrics']['ending_balance'], (float)$forecast['forecast'][11]['expected_balance'], 'Forecast headline reconciles to the monthly balance path');
assertEqual(true, (float)$forecast['metrics']['conservative_ending_balance'] <= (float)$forecast['metrics']['optimistic_ending_balance'], 'Forecast scenario endpoints remain correctly ordered');

$monthlyBudgets = Budget::getMonthly(8, 2026);
assertEqual(850.0, (float)$monthlyBudgets[0]['spent'], 'Budget dashboard totals monthly category spending with date ranges');
assertEqual(150.0, (float)$monthlyBudgets[0]['left'], 'Budget dashboard calculates remaining category runway');
$db->exec('DELETE FROM transactions');
$db->exec('DELETE FROM accounts');
$emptyForecast = ForecastDashboard::getSnapshot(new DateTimeImmutable('2026-08-15T12:00:00+01:00'));
assertEqual(false, (bool)$emptyForecast['has_data'], 'Forecast returns a clear no-history state');
assertEqual(12, count($emptyForecast['forecast']), 'No-history forecast keeps a stable twelve-period response shape');
assertEqual(0.0, (float)$emptyForecast['metrics']['ending_balance'], 'No-history forecast does not invent financial movement');

// --- Atomic, structured OFX import service ---
$db->exec('DELETE FROM transactions');
$db->exec('DELETE FROM accounts');
$db->exec('DELETE FROM category_tags');
$db->exec('DELETE FROM categories');
$db->exec('DELETE FROM tag_aliases');
$db->exec('DELETE FROM tags');
$importTagId = Tag::create('Import test', 'Complete merchant');
$db->exec("INSERT INTO categories (name) VALUES ('Imported activity')");
$importCategoryId = (int)$db->lastInsertId();
$db->exec("INSERT INTO category_tags (category_id, tag_id) VALUES ($importCategoryId, $importTagId)");
Tag::clearMatchCaches();
$serviceOfx = <<<OFX
<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS>
<CURDEF>GBP</CURDEF><BANKACCTFROM><BANKID>101010</BANKID><ACCTID>99887766</ACCTID><ACCTNAME>Import Test</ACCTNAME></BANKACCTFROM>
<BANKTRANLIST><DTSTART>20260801</DTSTART><DTEND>20260831</DTEND><STMTTRN><TRNTYPE>DEBIT</TRNTYPE><DTPOSTED>20260814</DTPOSTED><TRNAMT>-12.34</TRNAMT><FITID>LONG-BANK-IDENTIFIER-12345678901234567890</FITID><MEMO>Complete merchant statement memo</MEMO></STMTTRN></BANKTRANLIST>
<LEDGERBAL><BALAMT>1234.56</BALAMT><DTASOF>20260814</DTASOF></LEDGERBAL>
</STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>
OFX;
$importService = new OfxImportService($db);
$firstImport = $importService->importContent('current.ofx', $serviceOfx);
assertEqual('success', $firstImport['status'] ?? null, 'Structured importer reports a successful file');
assertEqual(1, (int)($firstImport['totals']['inserted'] ?? 0), 'Structured importer reports inserted transactions');
assertEqual(1, (int)($firstImport['totals']['tagged'] ?? 0), 'Structured importer reports newly tagged transactions');
assertEqual(1, (int)($firstImport['totals']['categorised'] ?? 0), 'Structured importer reports newly categorised transactions');
assertEqual('Complete merchant statement memo', $db->query('SELECT memo FROM transactions')->fetchColumn(), 'Imported transaction retains its complete memo');
assertEqual('Complete merchant statement memo', $db->query('SELECT description FROM transactions')->fetchColumn(), 'Imported transaction falls back to memo for its description');
$importAccountId = (int)$db->query("SELECT id FROM accounts WHERE account_number = '99887766'")->fetchColumn();
assertEqual(1234.56, (float)$db->query("SELECT ledger_balance FROM accounts WHERE id = $importAccountId")->fetchColumn(), 'OFX ledger balance is stored on the matched account');
$newerBalanceOfx = str_replace(['1234.56', '20260814'], ['1500.00', '20260815'], $serviceOfx);
$importService->importContent('newer-balance.ofx', $newerBalanceOfx);
$importService->importContent('older-balance.ofx', $serviceOfx);
assertEqual(1500.0, (float)$db->query("SELECT ledger_balance FROM accounts WHERE id = $importAccountId")->fetchColumn(), 'An older OFX statement cannot overwrite a newer ledger balance');
assertEqual('2026-08-15', $db->query("SELECT ledger_balance_date FROM accounts WHERE id = $importAccountId")->fetchColumn(), 'The newest OFX balance date is retained');
$repeatImport = $importService->importContent('current.ofx', $serviceOfx);
assertEqual(1, (int)($repeatImport['totals']['duplicates'] ?? 0), 'Repeat import reports the bank-ID duplicate');
assertEqual(1, (int)$db->query('SELECT COUNT(*) FROM transactions')->fetchColumn(), 'Repeat import does not add a duplicate row');

$fullCardId = Account::create('Existing card', null, '5522131234568609');
$maskedCardOfx = <<<OFX
<OFX><CREDITCARDMSGSRSV1><CCSTMTTRNRS><CCSTMTRS>
<CURDEF>GBP</CURDEF><CCACCTFROM><ACCTID>552213******8609</ACCTID></CCACCTFROM>
<LEDGERBAL><BALAMT>-2214.24</BALAMT><DTASOF>20260814</DTASOF></LEDGERBAL>
</CCSTMTRS></CCSTMTTRNRS></CREDITCARDMSGSRSV1></OFX>
OFX;
$importService->importContent('masked-card.ofx', $maskedCardOfx);
assertEqual(2, (int)$db->query('SELECT COUNT(*) FROM accounts')->fetchColumn(), 'A uniquely matching masked account does not create a duplicate account');
assertEqual(-2214.24, (float)$db->query("SELECT ledger_balance FROM accounts WHERE id = $fullCardId")->fetchColumn(), 'A masked OFX account updates its existing full-number account');

$balanceHistory = Account::buildBalanceHistory(80.0, '2026-08-09', [
    ['id' => 1, 'date' => '2026-08-08', 'amount' => 100.0],
    ['id' => 2, 'date' => '2026-08-09', 'amount' => -20.0],
    ['id' => 3, 'date' => '2026-08-10', 'amount' => -5.0],
]);
assertEqual(100.0, $balanceHistory[0]['balance'] ?? null, 'Balance history reconstructs the first post-transaction balance');
assertEqual(80.0, $balanceHistory[1]['balance'] ?? null, 'Balance history reconciles to the OFX snapshot');
assertEqual(75.0, $balanceHistory[2]['balance'] ?? null, 'Balance history applies transactions after the OFX snapshot forwards');
$brokenImport = $importService->importContent('broken.ofx', 'not an OFX statement');
assertEqual('error', $brokenImport['status'] ?? null, 'Malformed statement produces a structured error');

$db->exec('DROP TABLE category_tags');
$atomicOfx = str_replace(['20260814', 'LONG-BANK-IDENTIFIER-12345678901234567890'], ['20260815', 'ATOMIC-ROLLBACK-2'], $serviceOfx);
$atomicFailure = $importService->importContent('atomic.ofx', $atomicOfx);
assertEqual('error', $atomicFailure['status'] ?? null, 'Downstream import failure is reported');
assertEqual(1, (int)$db->query('SELECT COUNT(*) FROM transactions')->fetchColumn(), 'Failed file rolls back transactions inserted earlier in that file');

// --- Schema-only Database Health catalogue and repair workflow ---
$healthySchemaSnapshot = SchemaHealthService::expectedSnapshot();
$healthySchemaAudit = SchemaHealthService::analyseSnapshot($healthySchemaSnapshot);
assertEqual(true, $healthySchemaAudit['healthy'], 'Database Health accepts the canonical schema snapshot');
assertEqual(0, (int)$healthySchemaAudit['summary']['issues'], 'Canonical schema has no health issues');
assertEqual(['date'], $healthySchemaSnapshot['tables']['transactions']['indexes']['idx_transactions_date']['columns'] ?? null, 'Database Health includes the statement date index');
assertEqual(['created_at'], $healthySchemaSnapshot['tables']['logs']['indexes']['idx_logs_created_at']['columns'] ?? null, 'Database Health includes the log date index');

$equivalentSchemaSnapshot = $healthySchemaSnapshot;
foreach ($equivalentSchemaSnapshot['tables'] as &$equivalentTable) {
    foreach ($equivalentTable['foreign_keys'] as &$equivalentForeignKey) {
        if (($equivalentForeignKey['delete_rule'] ?? '') === 'RESTRICT') {
            $equivalentForeignKey['delete_rule'] = 'NO ACTION';
        }
    }
}
unset($equivalentTable, $equivalentForeignKey);
$equivalentSchemaSnapshot['tables']['transactions']['indexes']['date_lookup'] =
    $equivalentSchemaSnapshot['tables']['transactions']['indexes']['idx_transactions_date'];
unset($equivalentSchemaSnapshot['tables']['transactions']['indexes']['idx_transactions_date']);
$equivalentSchemaAudit = SchemaHealthService::analyseSnapshot($equivalentSchemaSnapshot);
assertEqual(true, $equivalentSchemaAudit['healthy'], 'Database Health accepts equivalent MySQL foreign-key actions and index names');
assertEqual(0, (int)$equivalentSchemaAudit['summary']['issues'], 'Equivalent schema metadata produces no false-positive issues');

$emptySchemaAudit = SchemaHealthService::analyseSnapshot([
    'driver' => 'mysql',
    'database' => 'empty',
    'server_version' => 'test',
    'tables' => [],
]);
$unsafeSchemaStatements = array_filter($emptySchemaAudit['issues'], function($issue) {
    return !empty($issue['repairable'])
        && preg_match('/^(?:CREATE\s+TABLE|ALTER\s+TABLE)\b/i', trim((string)($issue['sql'] ?? ''))) !== 1;
});
assertEqual(0, count($unsafeSchemaStatements), 'Every automatic repair is catalogue-generated schema DDL');

$driftedSchemaSnapshot = $healthySchemaSnapshot;
$driftedSchemaSnapshot['record_marker'] = ['unchanged-business-record'];
unset($driftedSchemaSnapshot['tables']['accounts']['columns']['ledger_balance_date']);
unset($driftedSchemaSnapshot['tables']['transactions']['indexes']['idx_transaction_fallback']);
$driftedSchemaSnapshot['tables']['transactions']['indexes']['unique_txn'] = [
    'unique' => true,
    'columns' => ['account_id', 'date', 'amount', 'description', 'memo'],
];
$driftedSchemaSnapshot['tables']['projects']['columns']['name']['length'] = 100;

$schemaExecutorCalls = [];
$schemaSnapshotProvider = function() use (&$driftedSchemaSnapshot) {
    return $driftedSchemaSnapshot;
};
$schemaExecutor = function(array $issue) use (&$driftedSchemaSnapshot, &$schemaExecutorCalls, $healthySchemaSnapshot) {
    $schemaExecutorCalls[] = $issue;
    $table = $issue['table'];
    $object = $issue['object'];
    if ($issue['kind'] === 'column') {
        $driftedSchemaSnapshot['tables'][$table]['columns'][$object] = $healthySchemaSnapshot['tables'][$table]['columns'][$object];
    } elseif ($issue['kind'] === 'index') {
        $driftedSchemaSnapshot['tables'][$table]['indexes'][$object] = $healthySchemaSnapshot['tables'][$table]['indexes'][$object];
    } elseif ($issue['kind'] === 'obsolete_index') {
        unset($driftedSchemaSnapshot['tables'][$table]['indexes'][$object]);
    }
};
$schemaHealthService = new SchemaHealthService(null, $schemaSnapshotProvider, $schemaExecutor);
$driftedSchemaAudit = $schemaHealthService->audit();
assertEqual(4, (int)$driftedSchemaAudit['summary']['issues'], 'Database Health finds missing, obsolete, and definition drift');
assertEqual(3, (int)$driftedSchemaAudit['summary']['repairable'], 'Database Health separates safe repairs from manual review');
assertEqual(1, (int)$driftedSchemaAudit['summary']['manual'], 'Definition changes remain manual');

$selectedSchemaRepairs = array_map(function($issue) {
    return $issue['id'];
}, array_values(array_filter($driftedSchemaAudit['issues'], function($issue) {
    return !empty($issue['repairable']);
})));
assertEqual(0, count($schemaExecutorCalls), 'Database Health audit is read-only');
$schemaRepairResult = $schemaHealthService->repair($selectedSchemaRepairs);
assertEqual('success', $schemaRepairResult['status'], 'Database Health applies selected catalogue repairs');
assertEqual(3, (int)$schemaRepairResult['summary']['succeeded'], 'Database Health reports each successful schema repair');
assertEqual(1, (int)$schemaRepairResult['audit']['summary']['issues'], 'Follow-up audit retains only the manual definition issue');
assertEqual(['unchanged-business-record'], $driftedSchemaSnapshot['record_marker'], 'Schema repairs leave business-record state untouched');
$recordChangingSql = array_filter($schemaExecutorCalls, function($issue) {
    return preg_match('/\b(?:INSERT|UPDATE|DELETE|TRUNCATE|REPLACE)\b/i', (string)($issue['sql'] ?? '')) === 1;
});
assertEqual(0, count($recordChangingSql), 'Database Health generates no record-changing SQL');
$secondSchemaRepair = $schemaHealthService->repair($selectedSchemaRepairs);
assertEqual(0, (int)$secondSchemaRepair['summary']['attempted'], 'Database Health repairs are idempotent');

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
