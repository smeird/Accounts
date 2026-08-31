<?php
require_once __DIR__ . '/../php_backend/models/User.php';
require_once __DIR__ . '/../php_backend/models/Passkey.php';
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
require_once __DIR__ . '/../php_backend/models/FinancialTrends.php';
require_once __DIR__ . '/../php_backend/models/DailyBurn.php';
require_once __DIR__ . '/../php_backend/models/Budget.php';
require_once __DIR__ . '/../php_backend/models/Project.php';
require_once __DIR__ . '/../php_backend/models/Account.php';
require_once __DIR__ . '/../php_backend/models/Setting.php';
require_once __DIR__ . '/../php_backend/AiTaggingPipeline.php';
require_once __DIR__ . '/../php_backend/AiCategoryTagger.php';
require_once __DIR__ . '/../php_backend/services/OfxImportService.php';
require_once __DIR__ . '/../php_backend/services/SchemaHealthService.php';
require_once __DIR__ . '/../php_backend/services/AiTagCorrectionService.php';
require_once __DIR__ . '/../php_backend/services/TagMigrationSafetyService.php';
require_once __DIR__ . '/../php_backend/TagTaxonomyPattern.php';
require_once __DIR__ . '/../php_backend/TagTaxonomyDiscoveryAi.php';
require_once __DIR__ . '/../php_backend/services/TagTaxonomyDiscoveryService.php';
require_once __DIR__ . '/../php_backend/services/TagTaxonomyCutoverService.php';
require_once __DIR__ . '/../php_backend/services/TaggingWorkspaceService.php';
require_once __DIR__ . '/../php_backend/services/TaggingFreshStartService.php';
require_once __DIR__ . '/../php_backend/services/FinancialWorkbookExportService.php';
require_once __DIR__ . '/../php_backend/services/RecurringPatternDetector.php';

// Use an in-memory SQLite database for tests.
putenv('DB_DSN=sqlite::memory:');
$db = Database::getConnection();

// Create minimal schema used by the models under test.
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, password TEXT);');
$db->exec("CREATE TABLE passkeys (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, credential_id TEXT NOT NULL, credential_id_hash TEXT NOT NULL UNIQUE, user_handle TEXT NOT NULL, public_key TEXT NOT NULL, sign_count INTEGER NOT NULL DEFAULT 0, transports TEXT, label TEXT NOT NULL DEFAULT 'Passkey', backup_eligible INTEGER NOT NULL DEFAULT 0, backed_up INTEGER NOT NULL DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, last_used_at DATETIME, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE);");
$db->exec('CREATE TABLE accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, sort_code TEXT, account_number TEXT, ledger_balance REAL DEFAULT 0, ledger_balance_date TEXT, closed INTEGER NOT NULL DEFAULT 0, closed_at TEXT);');
$db->exec("CREATE TABLE tags (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, name_normalized TEXT UNIQUE, keyword TEXT, description TEXT, origin TEXT NOT NULL DEFAULT 'legacy', status TEXT NOT NULL DEFAULT 'active', merged_into_tag_id INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP);");
$db->exec("CREATE TABLE tag_aliases (id INTEGER PRIMARY KEY AUTOINCREMENT, tag_id INTEGER, alias TEXT, alias_normalized TEXT, match_type TEXT, direction TEXT NOT NULL DEFAULT 'any', active TINYINT DEFAULT 1, origin TEXT NOT NULL DEFAULT 'legacy', confidence REAL, support_count INTEGER NOT NULL DEFAULT 0, last_matched_at DATETIME, created_at DATETIME, updated_at DATETIME, UNIQUE(alias_normalized, direction));");
$db->exec('CREATE TABLE segments (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, description TEXT, created_at DATETIME, updated_at DATETIME);');
$db->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, description TEXT, segment_id INTEGER, created_at DATETIME, updated_at DATETIME);');
$db->exec('CREATE TABLE category_tags (category_id INTEGER, tag_id INTEGER);');
$db->exec('CREATE TABLE segment_categories (segment_id INTEGER, category_id INTEGER);');
$db->exec('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT);');
$db->exec('CREATE TABLE transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, date TEXT, amount REAL, description TEXT, memo TEXT, category_id INTEGER, segment_id INTEGER, tag_id INTEGER, group_id INTEGER, transfer_id INTEGER, ofx_id TEXT, ofx_type TEXT, bank_ofx_id TEXT);');
$db->exec('CREATE TABLE transaction_groups (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, description TEXT, active TINYINT DEFAULT 1);');
$db->exec('CREATE TABLE budgets (id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER, month INTEGER, year INTEGER, amount REAL);');
$db->exec('CREATE TABLE logs (id INTEGER PRIMARY KEY AUTOINCREMENT, level TEXT, message TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);');
$db->exec('CREATE TABLE saved_reports (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, description TEXT, filters TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);');
$db->exec('CREATE TABLE totp_secrets (username TEXT PRIMARY KEY, secret TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);');
$db->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, description TEXT, rationale TEXT, cost_low REAL, cost_medium REAL, cost_high REAL, funding_source TEXT, recurring_cost REAL, estimated_time INTEGER, expected_lifespan INTEGER, benefit_financial REAL DEFAULT 0, weight_financial REAL DEFAULT 1, benefit_quality REAL DEFAULT 0, weight_quality REAL DEFAULT 1, benefit_risk REAL DEFAULT 0, weight_risk REAL DEFAULT 1, benefit_sustainability REAL DEFAULT 0, weight_sustainability REAL DEFAULT 1, dependencies TEXT, risks TEXT, archived TINYINT DEFAULT 0, group_id INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);');
$db->exec("CREATE TABLE tag_migration_runs (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'snapshot', contract_version TEXT NOT NULL DEFAULT 'v1', created_by TEXT, transaction_count INTEGER NOT NULL DEFAULT 0, eligible_count INTEGER NOT NULL DEFAULT 0, protected_transfer_count INTEGER NOT NULL DEFAULT 0, protected_ignore_count INTEGER NOT NULL DEFAULT 0, snapshot_hash TEXT NOT NULL DEFAULT '', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, discovery_started_at DATETIME, ready_at DATETIME, applied_at DATETIME, rolled_back_at DATETIME, cutover_summary TEXT);");
$db->exec("CREATE TABLE transaction_classification_snapshots (run_id INTEGER NOT NULL, transaction_id INTEGER NOT NULL, tag_id INTEGER, category_id INTEGER, segment_id INTEGER, eligible INTEGER NOT NULL DEFAULT 1, protection_reason TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (run_id, transaction_id));");
$db->exec("CREATE TABLE tag_taxonomy_proposals (id INTEGER PRIMARY KEY AUTOINCREMENT, run_id INTEGER NOT NULL, canonical_name TEXT NOT NULL, canonical_name_normalized TEXT NOT NULL, description TEXT, category_id INTEGER, confidence REAL, rationale TEXT, status TEXT NOT NULL DEFAULT 'pending', origin TEXT NOT NULL DEFAULT 'ai', pattern_count INTEGER NOT NULL DEFAULT 0, transaction_count INTEGER NOT NULL DEFAULT 0, absolute_amount REAL NOT NULL DEFAULT 0, reviewed_by TEXT, reviewed_at DATETIME, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE(run_id, canonical_name_normalized));");
$db->exec("CREATE TABLE tag_taxonomy_patterns (id INTEGER PRIMARY KEY AUTOINCREMENT, run_id INTEGER NOT NULL, proposal_id INTEGER, signature TEXT NOT NULL, alias TEXT NOT NULL, alias_normalized TEXT NOT NULL, direction TEXT NOT NULL, sample_description TEXT, sample_memo TEXT, current_tags TEXT, transaction_count INTEGER NOT NULL DEFAULT 0, absolute_amount REAL NOT NULL DEFAULT 0, first_seen TEXT, last_seen TEXT, confidence REAL, rationale TEXT, status TEXT NOT NULL DEFAULT 'pending', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE(run_id, signature));");
$db->exec("CREATE TABLE transaction_tag_proposals (run_id INTEGER NOT NULL, transaction_id INTEGER NOT NULL, pattern_id INTEGER NOT NULL, proposal_id INTEGER, current_tag_id INTEGER, confidence REAL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (run_id, transaction_id));");

$results = [];

function assertEqual($expected, $actual, string $message) {
    global $results;
    if ($expected === $actual) {
        $results[] = "PASS: $message";
    } else {
        $results[] = "FAIL: $message (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")";
    }
}

// Recurring bills are merchant/cadence patterns, not exact description/day
// duplicates. A first-Tuesday utility with changing bank references must be one
// pattern, while irregular or high-frequency merchants must remain excluded.
$recurringAsOf = new DateTimeImmutable('2026-08-29');
$affinityDates = [
    '2025-09-02', '2025-10-07', '2025-11-04', '2025-12-02',
    '2026-01-06', '2026-02-03', '2026-03-03', '2026-04-07',
    '2026-05-05', '2026-06-02', '2026-07-07', '2026-08-04',
];
$affinityRows = [];
foreach ($affinityDates as $index => $date) {
    $affinityRows[] = [
        'id' => $index + 1,
        'date' => $date,
        'amount' => -30 - ($index % 3),
        'description' => 'AFFINITY WATER PAYMENT REF ' . (4100 + $index),
        'memo' => null,
    ];
}
$affinityPatterns = RecurringPatternDetector::analyse($affinityRows, $recurringAsOf);
assertEqual(1, count($affinityPatterns), 'Recurring analysis consolidates a flexible monthly utility pattern');
assertEqual('Affinity Water', $affinityPatterns[0]['description'] ?? null, 'Recurring analysis removes changing bank reference text');
assertEqual(12, $affinityPatterns[0]['occurrences'] ?? null, 'Recurring analysis reports every utility occurrence in the year');
assertEqual('Monthly · first Tuesday', $affinityPatterns[0]['schedule'] ?? null, 'Recurring analysis recognises a weekday-based monthly schedule');
assertEqual(12, count($affinityPatterns[0]['descriptions'] ?? []), 'Recurring analysis retains raw descriptions for monthly-statement matching');

$irregularRows = [];
foreach (['2025-09-01', '2025-10-17', '2025-11-09', '2026-01-25', '2026-03-13', '2026-04-29', '2026-06-05', '2026-08-21'] as $index => $date) {
    $irregularRows[] = [
        'id' => 100 + $index,
        'date' => $date,
        'amount' => -20,
        'description' => 'MARKETPLACE ORDER ' . (9000 + $index),
        'memo' => null,
    ];
}
assertEqual(0, count(RecurringPatternDetector::analyse($irregularRows, $recurringAsOf)), 'Recurring analysis rejects irregular repeat shopping');

$frequentRows = [];
foreach (['2026-06-03', '2026-06-10', '2026-06-17', '2026-06-24', '2026-07-01', '2026-07-08', '2026-07-15', '2026-07-22', '2026-08-05', '2026-08-12', '2026-08-19', '2026-08-26'] as $index => $date) {
    $frequentRows[] = [
        'id' => 200 + $index,
        'date' => $date,
        'amount' => -45,
        'description' => 'WEEKLY GROCER',
        'memo' => null,
    ];
}
assertEqual(0, count(RecurringPatternDetector::analyse($frequentRows, $recurringAsOf)), 'Recurring analysis rejects high-frequency merchants from the monthly baseline');

// Database driver should be sqlite
assertEqual('sqlite', $db->getAttribute(PDO::ATTR_DRIVER_NAME), 'Database driver is sqlite');

$defaultAppearance = Setting::getBrand();
assertEqual('glass', $defaultAppearance['surface_style'], 'Appearance defaults to glass surfaces');
assertEqual('comfortable', $defaultAppearance['interface_density'], 'Appearance defaults to comfortable density');
assertEqual('soft', $defaultAppearance['corner_style'], 'Appearance defaults to soft corners');
assertEqual('balanced', $defaultAppearance['backdrop_strength'], 'Appearance defaults to a balanced backdrop');
assertEqual('standard', $defaultAppearance['motion_preference'], 'Appearance defaults to standard motion');
assertEqual('medium', $defaultAppearance['accent_bar_size'], 'Appearance defaults to a medium top accent bar');
assertEqual('medium', $defaultAppearance['page_header_size'], 'Appearance defaults to medium page headers');
assertEqual('#4f46e5', $defaultAppearance['brand_color'], 'Appearance exposes the default accent colour');
assertEqual(true, count(Setting::colorPalettes()) >= 20, 'Appearance offers an expanded curated colour palette');
assertEqual(true, isset(Setting::colorPalettes()['aurora'], Setting::colorPalettes()['graphite']), 'Appearance includes multitone and neutral palette choices');
assertEqual(true, isset(Setting::fontOptions()['Atkinson Hyperlegible'], Setting::fontOptions()['Lexend'], Setting::fontOptions()['Space Grotesk'], Setting::fontOptions()['Lora']), 'Typography offers additional accessible, modern and editorial fonts');
Setting::set('surface_style', 'paper');
Setting::set('interface_density', 'compact');
Setting::set('corner_style', 'balanced');
Setting::set('backdrop_strength', 'vivid');
Setting::set('motion_preference', 'reduced');
Setting::set('accent_bar_size', 'hairline');
Setting::set('page_header_size', 'small');
Setting::set('color_scheme', 'aurora');
Setting::set('font_heading', 'Lexend');
Setting::set('font_body', 'Atkinson Hyperlegible');
$customAppearance = Setting::getBrand();
assertEqual('paper', $customAppearance['surface_style'], 'Appearance returns the saved surface style');
assertEqual('compact', $customAppearance['interface_density'], 'Appearance returns the saved density');
assertEqual('balanced', $customAppearance['corner_style'], 'Appearance returns the saved corner style');
assertEqual('vivid', $customAppearance['backdrop_strength'], 'Appearance returns the saved backdrop strength');
assertEqual('reduced', $customAppearance['motion_preference'], 'Appearance returns the saved motion preference');
assertEqual('hairline', $customAppearance['accent_bar_size'], 'Appearance returns the saved hairline top accent bar size');
assertEqual('small', $customAppearance['page_header_size'], 'Appearance returns the saved page header size');
assertEqual('aurora', $customAppearance['color_scheme'], 'Appearance returns an expanded palette choice');
assertEqual('#7c3aed', $customAppearance['brand_color'], 'Appearance resolves the selected palette primary colour');
assertEqual('#0f766e', $customAppearance['brand_color_dark'], 'Appearance resolves the selected palette secondary colour');
assertEqual('Lexend', $customAppearance['heading_font'], 'Appearance returns an expanded heading font choice');
assertEqual('Atkinson Hyperlegible', $customAppearance['body_font'], 'Appearance returns an accessible body font choice');
Setting::set('interface_density', 'unsupported');
assertEqual('comfortable', Setting::getBrand()['interface_density'], 'Invalid appearance settings fall back safely');
Setting::set('font_body', 'Untrusted Remote Font');
assertEqual('', Setting::getBrand()['body_font'], 'Unknown font settings fall back without loading arbitrary resources');
$db->exec('DELETE FROM settings');

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

// Passkeys bind a discoverable credential to this relying party and origin,
// require user verification, and verify the authenticator's ES256 assertion.
function testCborLength(int $major, int $length): string {
    if ($length < 24) return chr(($major << 5) | $length);
    if ($length < 256) return chr(($major << 5) | 24) . chr($length);
    if ($length < 65536) return chr(($major << 5) | 25) . pack('n', $length);
    return chr(($major << 5) | 26) . pack('N', $length);
}
function testCborText(string $value): string { return testCborLength(3, strlen($value)) . $value; }
function testCborBytes(string $value): string { return testCborLength(2, strlen($value)) . $value; }

$passkeyPrivate = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
$passkeyDetails = openssl_pkey_get_details($passkeyPrivate);
$passkeyX = $passkeyDetails['ec']['x'];
$passkeyY = $passkeyDetails['ec']['y'];
$passkeyCose = chr(0xa5)
    . chr(0x01) . chr(0x02)
    . chr(0x03) . chr(0x26)
    . chr(0x20) . chr(0x01)
    . chr(0x21) . testCborBytes($passkeyX)
    . chr(0x22) . testCborBytes($passkeyY);
$passkeyChallenge = WebAuthn::base64urlEncode(random_bytes(32));
$passkeyCredentialRaw = random_bytes(32);
$passkeyCredentialId = WebAuthn::base64urlEncode($passkeyCredentialRaw);
$passkeyHandle = WebAuthn::base64urlEncode(random_bytes(32));
$passkeyExpected = ['challenge' => $passkeyChallenge, 'origin' => 'https://test.local', 'rp_id' => 'test.local'];
$passkeyClientData = json_encode(['type' => 'webauthn.create', 'challenge' => $passkeyChallenge, 'origin' => 'https://test.local', 'crossOrigin' => false]);
$passkeyRegistrationAuthData = hash('sha256', 'test.local', true)
    . chr(0x5d) . pack('N', 0) . str_repeat("\0", 16)
    . pack('n', strlen($passkeyCredentialRaw)) . $passkeyCredentialRaw . $passkeyCose;
$passkeyAttestation = chr(0xa3)
    . testCborText('fmt') . testCborText('none')
    . testCborText('attStmt') . chr(0xa0)
    . testCborText('authData') . testCborBytes($passkeyRegistrationAuthData);
$passkeyRegistrationPayload = [
    'id' => $passkeyCredentialId,
    'rawId' => $passkeyCredentialId,
    'type' => 'public-key',
    'response' => [
        'clientDataJSON' => WebAuthn::base64urlEncode($passkeyClientData),
        'attestationObject' => WebAuthn::base64urlEncode($passkeyAttestation),
        'transports' => ['internal'],
    ],
];
$passkeyVerifiedRegistration = WebAuthn::verifyRegistration($passkeyRegistrationPayload, $passkeyExpected);
assertEqual($passkeyCredentialId, $passkeyVerifiedRegistration['credential_id'], 'Passkey registration retains the authenticator credential ID');
assertEqual(1, $passkeyVerifiedRegistration['backup_eligible'], 'Passkey registration records sync eligibility');
assertEqual(1, $passkeyVerifiedRegistration['backed_up'], 'Passkey registration records current sync state');

$passkeyAuthChallenge = WebAuthn::base64urlEncode(random_bytes(32));
$passkeyAuthExpected = ['challenge' => $passkeyAuthChallenge, 'origin' => 'https://test.local', 'rp_id' => 'test.local'];
$passkeyAuthClientData = json_encode(['type' => 'webauthn.get', 'challenge' => $passkeyAuthChallenge, 'origin' => 'https://test.local', 'crossOrigin' => false]);
$passkeyAssertionData = hash('sha256', 'test.local', true) . chr(0x1d) . pack('N', 1);
openssl_sign($passkeyAssertionData . hash('sha256', $passkeyAuthClientData, true), $passkeySignature, $passkeyPrivate, OPENSSL_ALGO_SHA256);
$passkeyAuthPayload = [
    'id' => $passkeyCredentialId,
    'rawId' => $passkeyCredentialId,
    'type' => 'public-key',
    'response' => [
        'clientDataJSON' => WebAuthn::base64urlEncode($passkeyAuthClientData),
        'authenticatorData' => WebAuthn::base64urlEncode($passkeyAssertionData),
        'signature' => WebAuthn::base64urlEncode($passkeySignature),
        'userHandle' => $passkeyHandle,
    ],
];
$passkeyCredential = array_merge($passkeyVerifiedRegistration, ['user_handle' => $passkeyHandle]);
$passkeyVerifiedAuthentication = WebAuthn::verifyAuthentication($passkeyAuthPayload, $passkeyAuthExpected, $passkeyCredential);
assertEqual(1, $passkeyVerifiedAuthentication['sign_count'], 'Passkey authentication verifies the signed assertion counter');
$passkeyReplayBlocked = false;
try {
    WebAuthn::verifyAuthentication($passkeyAuthPayload, $passkeyAuthExpected, array_merge($passkeyCredential, ['sign_count' => 1]));
} catch (RuntimeException $e) {
    $passkeyReplayBlocked = true;
}
assertEqual(true, $passkeyReplayBlocked, 'Passkey authentication rejects a non-advancing signature counter');
$passkeyWrongOriginBlocked = false;
try {
    WebAuthn::verifyAuthentication($passkeyAuthPayload, array_merge($passkeyAuthExpected, ['origin' => 'https://wrong.test']), $passkeyCredential);
} catch (RuntimeException $e) {
    $passkeyWrongOriginBlocked = true;
}
assertEqual(true, $passkeyWrongOriginBlocked, 'Passkey authentication rejects a mismatched origin');

$storedPasskeyId = Passkey::create($userId, $passkeyHandle, $passkeyVerifiedRegistration, $passkeyRegistrationPayload, 'Test Mac');
assertEqual('Test Mac', Passkey::allForUser($userId)[0]['label'] ?? null, 'Passkey management lists the named credential');
assertEqual($userId, (int)(Passkey::findByCredentialId($passkeyCredentialId)['user_id'] ?? 0), 'Passkey lookup resolves the owning user without a username');
assertEqual(true, Passkey::recordUse($storedPasskeyId, 0, 1, 1), 'Passkey usage updates its audit and signature state');

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
$largeContextRows = [];
for ($contextTag = 1; $contextTag <= 25; $contextTag++) {
    $largeContextRows[] = ['tag_id' => 1000 + $contextTag, 'tag_name' => sprintf('Canonical %02d', $contextTag), 'alias' => str_repeat('merchant ', 8) . $contextTag];
}
$boundedContext = AiTaggingPipeline::buildAliasAwareTagContext($largeContextRows, 5, 180);
assertEqual(true, strpos($boundedContext['text'], 'Canonical 25') !== false, 'AI context retains the complete canonical allowlist when alias examples are truncated');
assertEqual(true, $boundedContext['truncated'], 'AI context reports truncated alias examples without truncating canonical names');
$workspaceSnapshot = (new TaggingWorkspaceService($db))->snapshot(10);
assertEqual(true, isset($workspaceSnapshot['metrics']['active_tags'], $workspaceSnapshot['tags'], $workspaceSnapshot['inbox']), 'Unified tagging workspace returns its health, catalogue and inbox contract');
assertEqual($tagId, (int)($workspaceSnapshot['tags'][0]['id'] ?? 0), 'Unified tagging workspace exposes the active canonical catalogue');

$categoryPrompt = AiCategoryTagger::buildPrompt([
    ['id' => 11, 'name' => 'Transport', 'description' => 'Travel and vehicle costs', 'assigned_tags' => ['Rail']],
], [
    ['id' => 7, 'name' => 'Fuel', 'keyword' => 'petrol', 'description' => '', 'transactions' => 4],
]);
assertEqual(true, strpos($categoryPrompt, '11: Transport') !== false && strpos($categoryPrompt, '7: Fuel') !== false, 'AI category prompt uses explicit existing category and candidate tag IDs');
$validatedCategoryAssignments = AiCategoryTagger::validateAssignments([
    'assignments' => [
        ['tag_id' => 7, 'category_id' => 11, 'confidence' => 0.94, 'reason' => 'Fuel is a transport cost'],
        ['tag_id' => 8, 'category_id' => 12, 'confidence' => 0.70, 'reason' => 'Uncertain'],
        ['tag_id' => 999, 'category_id' => 11, 'confidence' => 0.99, 'reason' => 'Unknown tag'],
        ['tag_id' => 7, 'category_id' => 12, 'confidence' => 0.99, 'reason' => 'Duplicate'],
    ],
], [7, 8], [11, 12]);
assertEqual(1, count($validatedCategoryAssignments['accepted']), 'AI category assignment accepts one high-confidence allowlisted match');
assertEqual(3, count($validatedCategoryAssignments['rejected']), 'AI category assignment rejects low-confidence, unknown, and duplicate suggestions');
assertEqual('{"assignments":[]}', AiCategoryTagger::extractOutputText(['output_text' => "```json\n{\"assignments\":[]}\n```"]), 'AI category assignment strips response code fences');

$tag2 = Tag::create('Fuel', null, null);
$lateAlphabeticalTag = Tag::create('Bills', null, null);
$alphabeticalTags = Tag::all();
assertEqual(['Bills', 'Food', 'Fuel'], array_column($alphabeticalTags, 'name'), 'Full tag listings are alphabetical regardless of creation order');
$db->exec('DELETE FROM tags WHERE id = ' . (int)$lateAlphabeticalTag);
$fuelOptions = Tag::searchOptions('fuel', 10);
assertEqual($tag2, (int)($fuelOptions[0]['id'] ?? 0), 'Compact tag search returns the matching canonical tag');
assertEqual(['id', 'name'], array_keys($fuelOptions[0] ?? []), 'Compact tag search returns only picker fields');
$limitedTagOptions = Tag::searchOptions('', 2);
assertEqual(2, count($limitedTagOptions), 'Compact tag search respects its result limit');
$literalWildcardOptions = Tag::searchOptions('%', 10);
assertEqual(0, count($literalWildcardOptions), 'Compact tag search treats wildcard characters literally');

// Safe permanent catalogue lifecycle: merge classifications, retire future use.
$db->exec("INSERT INTO segments (name) VALUES ('Tag lifecycle segment')");
$tagLifecycleSegment = (int)$db->lastInsertId();
$db->exec("INSERT INTO categories (name, segment_id) VALUES ('Tag lifecycle category', $tagLifecycleSegment)");
$tagLifecycleCategory = (int)$db->lastInsertId();
$tagMergeSource = Tag::create('Merge source');
$tagMergeTarget = Tag::create('Merge target');
$db->exec("INSERT INTO category_tags (category_id, tag_id) VALUES ($tagLifecycleCategory, $tagMergeSource)");
TagAlias::create($tagMergeSource, 'merge merchant', 'contains', true, 'manual', null, 1, 'outgoing');
$db->exec("INSERT INTO transactions (account_id, date, amount, description, category_id, segment_id, tag_id) VALUES (1, '2026-08-20', -10, 'Merge merchant', $tagLifecycleCategory, $tagLifecycleSegment, $tagMergeSource)");
$tagMergeTransaction = (int)$db->lastInsertId();
$mergeResult = Tag::merge($tagMergeSource, $tagMergeTarget);
assertEqual($tagMergeTarget, (int)$db->query("SELECT tag_id FROM transactions WHERE id=$tagMergeTransaction")->fetchColumn(), 'Canonical merge moves historical transaction assignments to the destination');
assertEqual($tagMergeTarget, (int)$db->query("SELECT tag_id FROM tag_aliases WHERE alias_normalized='merge merchant'")->fetchColumn(), 'Canonical merge moves deterministic rules to the destination');
assertEqual(['merged', $tagMergeTarget], $db->query("SELECT status, merged_into_tag_id FROM tags WHERE id=$tagMergeSource")->fetch(PDO::FETCH_NUM), 'Canonical merge retains the source as merged audit history');
assertEqual($tagLifecycleCategory, (int)$db->query("SELECT category_id FROM category_tags WHERE tag_id=$tagMergeTarget")->fetchColumn(), 'Canonical merge inherits the source reporting category when the target has none');
assertEqual(1, (int)$mergeResult['transactions_moved'], 'Canonical merge reports the moved transaction count');
$retireResult = Tag::retire($tagMergeTarget);
assertEqual('deprecated', $db->query("SELECT status FROM tags WHERE id=$tagMergeTarget")->fetchColumn(), 'Retiring a canonical tag removes it from future selection');
assertEqual($tagMergeTarget, (int)$db->query("SELECT tag_id FROM transactions WHERE id=$tagMergeTransaction")->fetchColumn(), 'Retiring a canonical tag retains historical transaction assignments');
assertEqual(0, (int)$db->query("SELECT active FROM tag_aliases WHERE alias_normalized='merge merchant'")->fetchColumn(), 'Retiring a canonical tag disables its matching rules');
assertEqual(1, (int)$retireResult['transactions_retained'], 'Tag retirement reports retained historical assignments');
$db->exec("DELETE FROM transactions WHERE id=$tagMergeTransaction");
$db->exec("DELETE FROM tag_aliases WHERE tag_id=$tagMergeTarget");
$db->exec("DELETE FROM category_tags WHERE tag_id IN ($tagMergeSource,$tagMergeTarget)");
$db->exec("DELETE FROM tags WHERE id IN ($tagMergeSource,$tagMergeTarget)");
$db->exec("DELETE FROM categories WHERE id=$tagLifecycleCategory");
$db->exec("DELETE FROM segments WHERE id=$tagLifecycleSegment");

$evidenceTag = Tag::create('Evidence tag');
$evidenceAlias = TagAlias::create($evidenceTag, 'evidence merchant', 'contains', true, 'manual', null, 0, 'outgoing');
$db->exec("INSERT INTO transactions (account_id, date, amount, description) VALUES (1, '2026-08-21', -5, 'Evidence merchant purchase')");
$evidenceTransaction = (int)$db->lastInsertId();
Tag::clearMatchCaches();
Tag::applyToAllTransactions();
$evidence = $db->query("SELECT support_count, last_matched_at FROM tag_aliases WHERE id=$evidenceAlias")->fetch(PDO::FETCH_ASSOC);
assertEqual(1, (int)$evidence['support_count'], 'Rule evidence records the number of deterministic matches');
assertEqual(true, !empty($evidence['last_matched_at']), 'Rule evidence records when the rule last matched');
$overlapTag = Tag::create('Overlap tag');
TagAlias::create($overlapTag, 'evidence merchant purchase', 'contains', true, 'manual', null, 0, 'outgoing');
$overlapWarnings = TagAlias::overlapWarnings('evidence merchant', $evidenceTag, 'outgoing');
assertEqual($overlapTag, (int)($overlapWarnings[0]['tag_id'] ?? 0), 'Rule validation detects cross-tag whole-word containment overlap');
$db->exec("DELETE FROM transactions WHERE id=$evidenceTransaction");
$db->exec("DELETE FROM tag_aliases WHERE tag_id IN ($evidenceTag,$overlapTag)");
$db->exec("DELETE FROM tags WHERE id IN ($evidenceTag,$overlapTag)");
$db->exec("DELETE FROM sqlite_sequence WHERE name='transactions'");
$retiredReuseTag = Tag::create('Retired reuse test', null, null, 'legacy');
$db->exec("UPDATE tags SET status='deprecated' WHERE id=$retiredReuseTag");
$reactivatedReuseTag = Tag::create('Retired reuse test', null, 'Promoted canonical tag', 'ai');
assertEqual($retiredReuseTag, $reactivatedReuseTag, 'Explicit canonical creation reuses a matching retired record without duplicating it');
assertEqual(['active', 'ai'], $db->query("SELECT status, origin FROM tags WHERE id=$retiredReuseTag")->fetch(PDO::FETCH_NUM), 'A deliberately reused retired tag is promoted back into the active catalogue');
$db->exec("DELETE FROM tags WHERE id=$retiredReuseTag");
$singularInterestTag = Tag::create('interest charge');
$pluralInterestTag = Tag::create('Interest Charges');
assertEqual($pluralInterestTag, Tag::getInterestChargeId(), 'Automatic interest tagging prefers the reviewed plural canonical tag');
$db->exec("DELETE FROM tags WHERE id IN ($singularInterestTag,$pluralInterestTag)");
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

$now = strtotime(date('Y-m-01'));
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

// Project priority must favour necessary work over accumulated nice-to-have benefits.
$criticalProject = ['benefit_risk' => 5, 'weight_risk' => 5, 'benefit_sustainability' => 5, 'benefit_financial' => 2, 'benefit_quality' => 0];
$cosmeticProject = ['benefit_risk' => 0, 'weight_risk' => 1, 'benefit_sustainability' => 0, 'benefit_financial' => 0, 'benefit_quality' => 5];
$preservationProject = ['benefit_risk' => 1, 'weight_risk' => 1, 'benefit_sustainability' => 5, 'benefit_financial' => 0, 'benefit_quality' => 0];
assertEqual(84, Project::calculatePriorityScore($criticalProject), 'Project priority applies the fixed 35/25/20/10/10 weights');
assertEqual('critical', Project::priorityTier($criticalProject)['key'], 'Severe and urgent work receives the critical override');
assertEqual('nice', Project::priorityTier($cosmeticProject)['key'], 'A strong cosmetic benefit alone remains nice to have');
assertEqual('preventive', Project::priorityTier($preservationProject)['key'], 'Strong asset preservation is surfaced as preventive work');
assertEqual(100, Project::calculatePriorityScore(['benefit_risk' => 99, 'weight_risk' => 99, 'benefit_sustainability' => 99, 'benefit_financial' => 99, 'benefit_quality' => 99]), 'Project priority clamps every rating to the 0-5 scale');

// Blank optional number inputs must persist as NULL under strict MySQL rules.
$blankProjectId = Project::create([
    'name' => 'Blank-number project',
    'cost_low' => '',
    'cost_medium' => ' ',
    'cost_high' => null,
    'recurring_cost' => '',
    'estimated_time' => '',
    'expected_lifespan' => ''
]);
$blankProject = $db->query('SELECT cost_low, cost_medium, cost_high, recurring_cost, estimated_time, expected_lifespan FROM projects WHERE id = ' . $blankProjectId)->fetch(PDO::FETCH_ASSOC);
foreach ($blankProject as $field => $value) {
    assertEqual(null, $value, 'Project creation stores blank ' . $field . ' as NULL');
}
Project::update($blankProjectId, [
    'name' => 'Updated blank-number project',
    'cost_low' => '',
    'cost_medium' => '1250.50',
    'cost_high' => '',
    'recurring_cost' => '',
    'estimated_time' => '6',
    'expected_lifespan' => ''
]);
$updatedBlankProject = $db->query('SELECT cost_low, cost_medium, cost_high, recurring_cost, estimated_time, expected_lifespan FROM projects WHERE id = ' . $blankProjectId)->fetch(PDO::FETCH_ASSOC);
assertEqual(null, $updatedBlankProject['cost_low'], 'Project updates retain blank decimal values as NULL');
assertEqual(1250.5, (float)$updatedBlankProject['cost_medium'], 'Project updates retain supplied decimal values');
assertEqual(null, $updatedBlankProject['cost_high'], 'Project updates store newly blank decimal values as NULL');
assertEqual(null, $updatedBlankProject['recurring_cost'], 'Project updates store blank recurring cost as NULL');
assertEqual(6, (int)$updatedBlankProject['estimated_time'], 'Project updates retain supplied integer values');
assertEqual(null, $updatedBlankProject['expected_lifespan'], 'Project updates store blank lifespan as NULL');
Project::delete($blankProjectId);

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
$closedAccount = Account::setClosed(2, true, '2026-08-10');
assertEqual(true, (bool)$closedAccount['closed'], 'An account can be marked closed');
assertEqual(0.0, (float)$db->query('SELECT ledger_balance FROM accounts WHERE id = 2')->fetchColumn(), 'Closing an account fixes its stored balance at zero');
assertEqual('closed', Account::updateLedgerBalance(2, 9000, '2026-08-11', 1), 'A closed account ignores later imported balances');
$instantWithClosedAccount = InstantDashboard::getSnapshot(new DateTimeImmutable('2026-08-10T12:00:00+01:00'));
assertEqual(2500.0, (float)$instantWithClosedAccount['headline']['balance'], 'Instant dashboard excludes closed accounts from its balance');
assertEqual(1, count($instantWithClosedAccount['accounts']), 'Instant dashboard hides closed accounts from its active account summary');
$closedSummaries = Account::getSummaries();
assertEqual(0.0, (float)$closedSummaries[1]['balance'], 'Account summaries expose a zero balance for a closed account');
Account::setClosed(2, false, '2026-08-10');
assertEqual('updated', Account::updateLedgerBalance(2, 7500, '2026-08-10', 1), 'A reopened account accepts a fresh bank balance');

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
$db->exec("UPDATE accounts SET closed = 1, closed_at = '2026-08-10', ledger_balance = 0 WHERE id = 2");
$closedGraphs = GraphsDashboard::getSnapshot(2026);
assertEqual(2500.0, (float)$closedGraphs['metrics']['balance'], 'Graphs excludes closed accounts from the net position');
assertEqual(1, count($closedGraphs['accounts']), 'Graphs excludes closed accounts from balance composition');
$closedForecast = ForecastDashboard::getSnapshot(new DateTimeImmutable('2026-08-10T12:00:00+01:00'));
assertEqual(2500.0, (float)$closedForecast['metrics']['starting_balance'], 'Forecast excludes closed accounts from its starting balance');
$db->exec("UPDATE accounts SET closed = 0, closed_at = NULL, ledger_balance = 7500 WHERE id = 2");

// --- Unified Financial Trends explorer ---
$db->exec("INSERT INTO transaction_groups (id, name, description) VALUES (20, 'House projects', 'Project spending')");
$db->exec("UPDATE transactions SET group_id = 20 WHERE amount < 0 AND transfer_id IS NULL");
$trends = FinancialTrends::getSnapshot('2026-07-01', '2026-08-10', 'segment', '2025-07-01', '2025-08-10');
assertEqual(6200.0, (float)$trends['metrics']['income'], 'Financial Trends totals income across the selected period');
assertEqual(2050.0, (float)$trends['metrics']['spending'], 'Financial Trends totals expense-only spending and excludes transfers');
assertEqual(4150.0, (float)$trends['metrics']['cashflow'], 'Financial Trends calculates net cash flow');
assertEqual(1900.0, (float)$trends['comparison']['metrics']['spending'], 'Financial Trends uses the explicit like-for-like comparison period');
assertEqual(150.0, (float)$trends['comparison']['changes']['spending']['amount'], 'Financial Trends reports absolute spending movement');
assertEqual('Household', $trends['breakdown'][0]['name'] ?? null, 'Financial Trends derives segments from the canonical category relationship');
assertEqual(2050.0, (float)($trends['breakdown'][0]['amount'] ?? 0), 'Financial Trends ranks expense-only drivers');
assertEqual(100.0, (float)$trends['coverage']['segment']['percentage'], 'Financial Trends reports selected-dimension coverage');
assertEqual('day', $trends['period']['grain'], 'Financial Trends uses a daily grain for short periods');
assertEqual(41, count($trends['series']), 'Financial Trends fills every daily bucket in the selected range');
$groupTrends = FinancialTrends::getSnapshot('2026-01-01', '2026-08-10', 'group');
assertEqual('House projects', $groupTrends['breakdown'][0]['name'] ?? null, 'Groups are a selectable breakdown rather than a separate dashboard');
assertEqual(100.0, (float)$groupTrends['coverage']['group']['percentage'], 'Group coverage is calculated from expense value');
$trendDrilldown = Transaction::search('Home', null, null, '2026-08-01', '2026-08-10');
assertEqual(1, count($trendDrilldown), 'Financial Trends drill-down links constrain transaction search to the selected period');
$exactTrendDrilldown = Transaction::search(null, null, null, '2026-07-01', '2026-08-10', 'segment', 10, false, true);
assertEqual(2, count($exactTrendDrilldown), 'Financial Trends drill-down links use the exact classification and expense-only scope');

// --- Calendar-normalised Daily Burn dashboard ---
$dailyBurn = DailyBurn::getSnapshot('2026-07-01', '2026-08-10');
assertEqual(2050.0, (float)$dailyBurn['metrics']['total_spending'], 'Daily Burn totals expense-only spending and excludes transfers');
assertEqual(27.42, (float)$dailyBurn['metrics']['latest_daily_burn'], 'Daily Burn divides the latest month by its calendar days');
assertEqual(33.07, (float)$dailyBurn['metrics']['average_daily_burn'], 'Daily Burn averages the calendar-normalised monthly rates');
assertEqual(41, count($dailyBurn['daily']), 'Daily Burn fills every actual day in the requested history');
assertEqual(2, count($dailyBurn['months']), 'Daily Burn returns one rate point per calendar month');
assertEqual('Household', $dailyBurn['segments'][0]['name'] ?? null, 'Daily Burn derives the segment from the canonical category relationship');
assertEqual(33.07, (float)($dailyBurn['segments'][0]['average_daily_burn'] ?? 0), 'Daily Burn calculates the average daily rate by segment');
assertEqual(1200.0, (float)($dailyBurn['metrics']['peak_day']['amount'] ?? 0), 'Daily Burn keeps actual transaction-day spikes separate');

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
$emptyZeroBalanceOfx = <<<OFX
<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS>
<CURDEF>GBP</CURDEF><BANKACCTFROM><BANKID>101010</BANKID><ACCTID>99887766</ACCTID><ACCTNAME>Import Test</ACCTNAME></BANKACCTFROM>
<LEDGERBAL><BALAMT>0.00</BALAMT><DTASOF>20260816</DTASOF></LEDGERBAL>
</STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>
OFX;
$protectedZeroImport = $importService->importContent('empty-zero-balance.ofx', $emptyZeroBalanceOfx);
assertEqual('protected', $protectedZeroImport['accounts'][0]['balance_status'] ?? null, 'An empty OFX zero balance is identified as an unreliable placeholder');
assertEqual(1, (int)($protectedZeroImport['totals']['balances_protected'] ?? 0), 'Protected balance placeholders are counted in import results');
assertEqual(1, (int)($protectedZeroImport['totals']['warnings'] ?? 0), 'Protected balance placeholders are visible as import warnings');
assertEqual(1500.0, (float)$db->query("SELECT ledger_balance FROM accounts WHERE id = $importAccountId")->fetchColumn(), 'An empty OFX zero balance cannot overwrite a known balance');
assertEqual('2026-08-15', $db->query("SELECT ledger_balance_date FROM accounts WHERE id = $importAccountId")->fetchColumn(), 'A protected zero placeholder cannot advance the balance date');

// Simulate a zero placeholder saved by an older importer, then prove a real
// non-zero snapshot can repair it when no transactions occurred in between.
$db->exec("UPDATE accounts SET ledger_balance = 0, ledger_balance_date = '2026-08-16' WHERE id = $importAccountId");
$recoveredBalanceImport = $importService->importContent('recover-balance.ofx', $newerBalanceOfx);
assertEqual('recovered', $recoveredBalanceImport['accounts'][0]['balance_status'] ?? null, 'A genuine older snapshot repairs a newer zero placeholder without intervening activity');
assertEqual(1, (int)($recoveredBalanceImport['totals']['balances_updated'] ?? 0), 'Recovered balances are reported as refreshed');
assertEqual(1500.0, (float)$db->query("SELECT ledger_balance FROM accounts WHERE id = $importAccountId")->fetchColumn(), 'Recovered balance stores the genuine non-zero amount');
assertEqual('2026-08-16', $db->query("SELECT ledger_balance_date FROM accounts WHERE id = $importAccountId")->fetchColumn(), 'Balance recovery retains the later reconciled date');
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
$db->exec('CREATE TABLE category_tags (category_id INTEGER, tag_id INTEGER)');

// --- Schema-only Database Health catalogue and repair workflow ---
$healthySchemaSnapshot = SchemaHealthService::expectedSnapshot();
$healthySchemaAudit = SchemaHealthService::analyseSnapshot($healthySchemaSnapshot);
assertEqual(true, $healthySchemaAudit['healthy'], 'Database Health accepts the canonical schema snapshot');
assertEqual(0, (int)$healthySchemaAudit['summary']['issues'], 'Canonical schema has no health issues');
assertEqual(['date'], $healthySchemaSnapshot['tables']['transactions']['indexes']['idx_transactions_date']['columns'] ?? null, 'Database Health includes the statement date index');
assertEqual(['created_at'], $healthySchemaSnapshot['tables']['logs']['indexes']['idx_logs_created_at']['columns'] ?? null, 'Database Health includes the log date index');
assertEqual(true, isset($healthySchemaSnapshot['tables']['accounts']['columns']['closed'], $healthySchemaSnapshot['tables']['accounts']['columns']['closed_at']), 'Database Health includes closed-account lifecycle fields');
assertEqual(['credential_id_hash'], $healthySchemaSnapshot['tables']['passkeys']['indexes']['credential_id_hash']['columns'] ?? null, 'Database Health includes unique passkey credential lookup');
assertEqual('CASCADE', $healthySchemaSnapshot['tables']['passkeys']['foreign_keys'][0]['delete_rule'] ?? null, 'Database Health links passkeys safely to users');

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

// AI Data Fix must constrain the model to allowlisted tags and must update no
// transaction field other than tag_id.
$db->exec("INSERT INTO tags (name, name_normalized) VALUES ('Incorrect loan tag', 'incorrect loan tag'), ('Mortgage', 'mortgage')");
$incorrectTagId = (int)$db->query("SELECT id FROM tags WHERE name_normalized='incorrect loan tag'")->fetchColumn();
$mortgageTagId = (int)$db->query("SELECT id FROM tags WHERE name_normalized='mortgage'")->fetchColumn();
$db->exec("INSERT INTO tag_aliases (tag_id, alias, alias_normalized, match_type, active) VALUES ($incorrectTagId, 'BANK OF IRELAND', 'bank of ireland', 'contains', 1)");
$db->exec("INSERT INTO category_tags (category_id, tag_id) VALUES (1, $incorrectTagId)");
$correctionAccountId = (int)$db->query('SELECT id FROM accounts ORDER BY id LIMIT 1')->fetchColumn();
if (!$correctionAccountId) {
    $db->exec("INSERT INTO accounts (name) VALUES ('Correction test account')");
    $correctionAccountId = (int)$db->lastInsertId();
}
$insertCorrectionTransaction = $db->prepare('INSERT INTO transactions (account_id, date, amount, description, memo, category_id, segment_id, tag_id, group_id, transfer_id) VALUES (?,?,?,?,?,?,?,?,?,?)');
$insertCorrectionTransaction->execute([$correctionAccountId, '2026-08-01', -1200.50, 'BANK OF IRELAND HOME LOAN', 'Monthly payment', null, null, $incorrectTagId, null, null]);
$correctionTransactionId = (int)$db->lastInsertId();
$insertCorrectionTransaction->execute([$correctionAccountId, '2026-08-02', -450.00, 'OTHER BANK PERSONAL LOAN', 'Valid loan payment', null, null, $incorrectTagId, null, null]);
$beforeCorrection = $db->query("SELECT * FROM transactions WHERE id=$correctionTransactionId")->fetch(PDO::FETCH_ASSOC);
$correctionService = new AiTagCorrectionService($db);
$correctionTags = $correctionService->tagContext();
$correctionPlan = $correctionService->createPlan(
    'Bank of Ireland transactions tagged Incorrect loan tag should be Mortgage',
    [
        'summary' => 'Move Bank of Ireland mortgage payments to Mortgage.',
        'source_tag_ids' => [$incorrectTagId],
        'target_tag_id' => $mortgageTagId,
        'target_tag_name' => 'Mortgage',
        'match_terms' => ['Bank of Ireland'],
        'confidence' => 0.98,
        'warnings' => [],
    ],
    $correctionTags
);
assertEqual(1, $correctionPlan['affected_count'], 'AI tag correction previews the exact matching transaction set');
$correctionResult = $correctionService->applyPlan($correctionPlan, true);
$afterCorrection = $db->query("SELECT * FROM transactions WHERE id=$correctionTransactionId")->fetch(PDO::FETCH_ASSOC);
assertEqual(1, $correctionResult['updated'], 'AI tag correction updates the confirmed transaction');
assertEqual($mortgageTagId, (int)$afterCorrection['tag_id'], 'AI tag correction changes the transaction tag');
$beforeNonTag = $beforeCorrection; $afterNonTag = $afterCorrection;
unset($beforeNonTag['tag_id'], $afterNonTag['tag_id']);
assertEqual($beforeNonTag, $afterNonTag, 'AI tag correction leaves every non-tag transaction field unchanged');
assertEqual(1, (int)$db->query("SELECT COUNT(*) FROM tags WHERE id=$incorrectTagId")->fetchColumn(), 'AI tag correction retains a source tag that still has valid transactions');
assertEqual($mortgageTagId, (int)$db->query("SELECT tag_id FROM tag_aliases WHERE alias_normalized='bank of ireland'")->fetchColumn(), 'AI tag correction moves reusable alias rules to the destination tag');
$db->exec("INSERT INTO tags (name, name_normalized) VALUES ('Obsolete mortgage tag', 'obsolete mortgage tag')");
$obsoleteTagId = (int)$db->lastInsertId();
$insertCorrectionTransaction->execute([$correctionAccountId, '2026-08-03', -900.00, 'OLD MORTGAGE PAYMENT', null, null, null, $obsoleteTagId, null, null]);
$cleanupTags = $correctionService->tagContext();
$cleanupPlan = $correctionService->createPlan(
    'Every Obsolete mortgage tag transaction should be Mortgage',
    ['summary' => 'Merge the obsolete tag.', 'source_tag_ids' => [$obsoleteTagId], 'target_tag_id' => $mortgageTagId, 'target_tag_name' => 'Mortgage', 'match_terms' => [], 'confidence' => 0.99],
    $cleanupTags
);
$correctionService->applyPlan($cleanupPlan, true);
assertEqual(['merged', $mortgageTagId], $db->query("SELECT status, merged_into_tag_id FROM tags WHERE id=$obsoleteTagId")->fetch(PDO::FETCH_NUM), 'AI tag correction retains an emptied source tag as merged audit history');
try {
    $correctionService->createPlan(
        'Fix Bank of Ireland',
        ['source_tag_ids' => [999999], 'target_tag_id' => $mortgageTagId, 'match_terms' => ['Bank of Ireland'], 'confidence' => 1],
        $correctionTags
    );
    $unknownTagRejected = false;
} catch (InvalidArgumentException $e) {
    $unknownTagRejected = true;
}
assertEqual(true, $unknownTagRejected, 'AI tag correction rejects source tags outside the server allowlist');

// Tag taxonomy rebuilds must begin with a complete, immutable classification
// snapshot and must be reversible without touching later transactions.
$migrationSafety = new TagMigrationSafetyService($db);
assertEqual(true, $migrationSafety->schemaReady(), 'Tag rebuild safety detects its complete schema');
$migrationContract = TagMigrationSafetyService::contract();
assertEqual(0, $migrationContract['success_thresholds']['unreviewed_new_tags'] ?? null, 'Tag rebuild contract permits no unreviewed canonical tags');
assertEqual(95, $migrationContract['success_thresholds']['eligible_coverage_percent'] ?? null, 'Tag rebuild contract uses the agreed 95% reviewed coverage threshold');
$ignoreTagId = Tag::getIgnoreId();
$safetyAccountId = (int)$db->query('SELECT id FROM accounts ORDER BY id LIMIT 1')->fetchColumn();
$insertSafetyTransaction = $db->prepare('INSERT INTO transactions (account_id, date, amount, description, memo, category_id, segment_id, tag_id, group_id, transfer_id) VALUES (?,?,?,?,?,?,?,?,?,?)');
$insertSafetyTransaction->execute([$safetyAccountId, '2026-08-21', -12.34, 'SNAPSHOT ELIGIBLE', null, null, null, $mortgageTagId, null, null]);
$snapshotEligibleId = (int)$db->lastInsertId();
$insertSafetyTransaction->execute([$safetyAccountId, '2026-08-22', -1.00, 'SNAPSHOT EXCLUDED', null, null, null, $ignoreTagId, null, null]);
$snapshotIgnoredId = (int)$db->lastInsertId();
$insertSafetyTransaction->execute([$safetyAccountId, '2026-08-23', -20.00, 'SNAPSHOT TRANSFER', null, null, null, null, null, 987654]);
$snapshotTransferId = (int)$db->lastInsertId();
$migrationPreview = $migrationSafety->currentClassificationPreview();
assertEqual($migrationPreview['transaction_count'], $migrationPreview['eligible_count'] + $migrationPreview['protected_transfer_count'] + $migrationPreview['protected_ignore_count'], 'Tag rebuild preview assigns every transaction to one safety scope');
$migrationRun = $migrationSafety->createSnapshot('Automated safety baseline', 'test-suite');
assertEqual($migrationPreview['transaction_count'], $migrationRun['snapshot_rows'], 'Classification snapshot records every current transaction');
assertEqual('ignored', $db->query("SELECT protection_reason FROM transaction_classification_snapshots WHERE run_id={$migrationRun['id']} AND transaction_id=$snapshotIgnoredId")->fetchColumn(), 'IGNORE-tagged transactions are protected in the snapshot');
assertEqual('transfer', $db->query("SELECT protection_reason FROM transaction_classification_snapshots WHERE run_id={$migrationRun['id']} AND transaction_id=$snapshotTransferId")->fetchColumn(), 'Confirmed transfers are protected in the snapshot');
assertEqual($mortgageTagId, (int)$db->query("SELECT tag_id FROM transactions WHERE id=$snapshotEligibleId")->fetchColumn(), 'Creating a snapshot does not change live tags');

$db->exec("UPDATE transactions SET tag_id=NULL, category_id=NULL, segment_id=NULL WHERE id IN ($snapshotEligibleId, $snapshotIgnoredId)");
$insertSafetyTransaction->execute([$safetyAccountId, '2026-08-24', -3.21, 'IMPORTED AFTER SNAPSHOT', null, null, null, null, null, null]);
$afterSnapshotTransactionId = (int)$db->lastInsertId();
$rollbackPreview = $migrationSafety->rollbackPreview((int)$migrationRun['id']);
assertEqual(2, $rollbackPreview['changed_transactions'], 'Snapshot restore previews the changed classification rows');
assertEqual(1, $rollbackPreview['protected_changes'], 'Snapshot restore identifies changes to protected classifications');
assertEqual(true, $rollbackPreview['new_transactions_untouched'] >= 1, 'Snapshot restore reports later transactions that remain untouched');
assertEqual(true, $rollbackPreview['restorable'], 'An intact classification snapshot is restorable');
$restoreResult = $migrationSafety->restoreSnapshot((int)$migrationRun['id']);
assertEqual(2, $restoreResult['restored_transactions'], 'Snapshot restore reports the classifications it changed');
assertEqual($mortgageTagId, (int)$db->query("SELECT tag_id FROM transactions WHERE id=$snapshotEligibleId")->fetchColumn(), 'Snapshot restore returns the original canonical tag');
assertEqual($ignoreTagId, (int)$db->query("SELECT tag_id FROM transactions WHERE id=$snapshotIgnoredId")->fetchColumn(), 'Snapshot restore reinstates the protected IGNORE tag');
assertEqual(0, (int)$db->query("SELECT COUNT(*) FROM transaction_classification_snapshots WHERE run_id={$migrationRun['id']} AND transaction_id=$afterSnapshotTransactionId")->fetchColumn(), 'Transactions imported later are not added to an immutable snapshot');
$snapshotEligibleState = (int)$db->query("SELECT eligible FROM transaction_classification_snapshots WHERE run_id={$migrationRun['id']} AND transaction_id=$snapshotEligibleId")->fetchColumn();
$db->exec("UPDATE transaction_classification_snapshots SET eligible=" . ($snapshotEligibleState ? 0 : 1) . " WHERE run_id={$migrationRun['id']} AND transaction_id=$snapshotEligibleId");
assertEqual(false, $migrationSafety->rollbackPreview((int)$migrationRun['id'])['hash_valid'], 'Snapshot integrity check detects altered recovery evidence');
$db->exec("UPDATE transaction_classification_snapshots SET eligible=$snapshotEligibleState WHERE run_id={$migrationRun['id']} AND transaction_id=$snapshotEligibleId");
assertEqual(true, $migrationSafety->rollbackPreview((int)$migrationRun['id'])['hash_valid'], 'Restoring snapshot evidence returns its integrity check to valid');

// Phase 2 must reduce changing bank wording to reusable patterns and stage AI
// proposals without touching the active taxonomy or any live classification.
$patternOne = TagTaxonomyPattern::fromTransaction('PHASE TWO SHOP 123456', null, -10);
$patternTwo = TagTaxonomyPattern::fromTransaction('PHASE TWO SHOP 987654', null, -20);
$incomingPattern = TagTaxonomyPattern::fromTransaction('PHASE TWO SHOP 123456', null, 10);
assertEqual($patternOne['signature'], $patternTwo['signature'], 'Taxonomy discovery removes changing bank references from pattern identity');
assertEqual(false, $patternOne['signature'] === $incomingPattern['signature'], 'Taxonomy discovery keeps income and outgoing patterns separate');
$db->exec("INSERT INTO categories (name, description) VALUES ('Taxonomy Test Category', 'Used by Phase 2 tests')");
$taxonomyCategoryId = (int)$db->lastInsertId();
$insertSafetyTransaction->execute([$safetyAccountId, '2026-08-24', -14.00, 'PHASE TWO SHOP 123456', null, null, null, $mortgageTagId, null, null]);
$taxonomyTransactionOne = (int)$db->lastInsertId();
$insertSafetyTransaction->execute([$safetyAccountId, '2026-08-24', -16.00, 'PHASE TWO SHOP 987654', null, null, null, $mortgageTagId, null, null]);
$taxonomyTransactionTwo = (int)$db->lastInsertId();
$taxonomyRun = $migrationSafety->createSnapshot('Phase 2 discovery baseline', 'test-suite');
$taxonomyService = new TagTaxonomyDiscoveryService($db);
assertEqual(true, $taxonomyService->schemaReady(), 'Taxonomy discovery detects its complete staging schema');
$liveClassificationsBeforeDiscovery = $db->query("SELECT id, tag_id, category_id, segment_id FROM transactions WHERE id IN ($taxonomyTransactionOne,$taxonomyTransactionTwo) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$liveTagCountBeforeDiscovery = (int)$db->query('SELECT COUNT(*) FROM tags')->fetchColumn();
$liveAliasCountBeforeDiscovery = (int)$db->query('SELECT COUNT(*) FROM tag_aliases')->fetchColumn();
$taxonomyPrepared = $taxonomyService->prepare((int)$taxonomyRun['id']);
assertEqual('staging', $taxonomyPrepared['selected_run']['status'] ?? null, 'Preparing discovery opens a review-only staging run');
assertEqual((int)$taxonomyRun['eligible_count'], (int)$db->query("SELECT COUNT(*) FROM transaction_tag_proposals WHERE run_id={$taxonomyRun['id']}")->fetchColumn(), 'Discovery stages every eligible snapshotted transaction');
assertEqual(0, (int)$db->query("SELECT COUNT(*) FROM transaction_tag_proposals WHERE run_id={$taxonomyRun['id']} AND transaction_id IN ($snapshotIgnoredId,$snapshotTransferId)")->fetchColumn(), 'Discovery excludes protected transfers and IGNORE transactions');
$taxonomyPatternOne = (int)$db->query("SELECT pattern_id FROM transaction_tag_proposals WHERE run_id={$taxonomyRun['id']} AND transaction_id=$taxonomyTransactionOne")->fetchColumn();
$taxonomyPatternTwo = (int)$db->query("SELECT pattern_id FROM transaction_tag_proposals WHERE run_id={$taxonomyRun['id']} AND transaction_id=$taxonomyTransactionTwo")->fetchColumn();
assertEqual($taxonomyPatternOne, $taxonomyPatternTwo, 'Repeat bank references collapse into one reusable staged alias');
$validatedTaxonomy = TagTaxonomyDiscoveryAi::validate([
    'assignments' => [
        ['pattern_id' => $taxonomyPatternOne, 'canonical_tag' => 'Retail essentials', 'description' => 'Everyday essential retail purchases', 'category_id' => $taxonomyCategoryId, 'confidence' => 0.94, 'reason' => 'Repeat outgoing retailer pattern'],
        ['pattern_id' => 999999, 'canonical_tag' => 'Unknown', 'category_id' => $taxonomyCategoryId, 'confidence' => 0.99],
        ['pattern_id' => $taxonomyPatternOne, 'canonical_tag' => 'IGNORE', 'category_id' => $taxonomyCategoryId, 'confidence' => 0.99],
    ],
], [$taxonomyPatternOne], [$taxonomyCategoryId]);
assertEqual(1, count($validatedTaxonomy['accepted']), 'Taxonomy AI validation accepts one allowlisted reusable proposal');
assertEqual(2, count($validatedTaxonomy['rejected']), 'Taxonomy AI validation rejects unknown and duplicate pattern suggestions');
$protectedTaxonomyName = TagTaxonomyDiscoveryAi::validate(['assignments' => [
    ['pattern_id' => $taxonomyPatternOne, 'canonical_tag' => 'IGNORE', 'category_id' => $taxonomyCategoryId, 'confidence' => 0.99],
]], [$taxonomyPatternOne], [$taxonomyCategoryId]);
assertEqual('protected_or_rejected_name', $protectedTaxonomyName['rejected'][0]['reason'] ?? null, 'Taxonomy AI validation rejects the protected IGNORE name');
$taxonomyStaged = $taxonomyService->applyAiAssignments((int)$taxonomyRun['id'], $validatedTaxonomy['accepted']);
$taxonomyProposalId = (int)$db->query("SELECT proposal_id FROM tag_taxonomy_patterns WHERE id=$taxonomyPatternOne")->fetchColumn();
assertEqual(true, $taxonomyProposalId > 0, 'AI taxonomy output creates a staged canonical proposal');
assertEqual(true, (float)$taxonomyStaged['metrics']['coverage_percent'] > 0, 'Taxonomy staging reports transaction coverage');
$taxonomyReviewed = $taxonomyService->reviewProposal((int)$taxonomyRun['id'], $taxonomyProposalId, [
    'canonical_name' => 'Retail essentials',
    'description' => 'Reviewed durable definition',
    'category_id' => $taxonomyCategoryId,
    'status' => 'approved',
], 'test-suite');
assertEqual('approved', $db->query("SELECT status FROM tag_taxonomy_proposals WHERE id=$taxonomyProposalId")->fetchColumn(), 'A person can approve a staged canonical tag');
assertEqual($liveClassificationsBeforeDiscovery, $db->query("SELECT id, tag_id, category_id, segment_id FROM transactions WHERE id IN ($taxonomyTransactionOne,$taxonomyTransactionTwo) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC), 'Phase 2 leaves live transaction classifications unchanged');
assertEqual($liveTagCountBeforeDiscovery, (int)$db->query('SELECT COUNT(*) FROM tags')->fetchColumn(), 'Phase 2 creates no live canonical tags');
assertEqual($liveAliasCountBeforeDiscovery, (int)$db->query('SELECT COUNT(*) FROM tag_aliases')->fetchColumn(), 'Phase 2 creates no live aliases');
try {
    $taxonomyService->markReady((int)$taxonomyRun['id']);
    $incompleteTaxonomyBlocked = false;
} catch (RuntimeException $e) {
    $incompleteTaxonomyBlocked = true;
}
assertEqual(true, $incompleteTaxonomyBlocked, 'An incomplete staged taxonomy cannot be marked ready');
try {
    $taxonomyService->markReady((int)$taxonomyRun['id'], true);
    $lowCoverageTaxonomyBlocked = false;
} catch (RuntimeException $e) {
    $lowCoverageTaxonomyBlocked = true;
}
assertEqual(true, $lowCoverageTaxonomyBlocked, 'Early finish remains blocked below 95% transaction coverage');

$db->exec("INSERT INTO tag_migration_runs (name, status, transaction_count, eligible_count, snapshot_hash, discovery_started_at) VALUES ('Early finish test', 'staging', 100, 100, 'early-finish-test-hash', CURRENT_TIMESTAMP)");
$earlyFinishRunId = (int)$db->lastInsertId();
$db->exec("INSERT INTO tag_taxonomy_proposals (run_id, canonical_name, canonical_name_normalized, description, status, origin, pattern_count, transaction_count, absolute_amount, reviewed_by, reviewed_at) VALUES ($earlyFinishRunId, 'Approved majority', 'approved majority', 'Reviewed majority proposal', 'approved', 'ai', 1, 95, 950, 'test-suite', CURRENT_TIMESTAMP)");
$earlyFinishProposalId = (int)$db->lastInsertId();
$db->exec("INSERT INTO tag_taxonomy_patterns (run_id, proposal_id, signature, alias, alias_normalized, direction, transaction_count, absolute_amount, confidence, status) VALUES ($earlyFinishRunId, $earlyFinishProposalId, 'early-finish-approved-pattern', 'APPROVED MAJORITY', 'approved majority', 'outgoing', 95, 950, 0.95, 'proposed')");
$db->exec("INSERT INTO tag_taxonomy_patterns (run_id, signature, alias, alias_normalized, direction, transaction_count, absolute_amount, status) VALUES ($earlyFinishRunId, 'early-finish-deferred-pattern', 'UNCOMMON REMAINDER', 'uncommon remainder', 'outgoing', 5, 50, 'pending')");
$earlyFinishLiveState = $db->query("SELECT COUNT(*) AS tags, (SELECT COUNT(*) FROM tag_aliases) AS aliases, (SELECT COUNT(*) FROM transactions WHERE tag_id IS NOT NULL) AS tagged_transactions FROM tags")->fetch(PDO::FETCH_ASSOC);
$earlyFinishView = $taxonomyService->markReady($earlyFinishRunId, true);
assertEqual('ready', $earlyFinishView['selected_run']['status'] ?? null, 'A reviewed taxonomy can finish at exactly 95% coverage');
assertEqual(95.0, $earlyFinishView['metrics']['coverage_percent'] ?? null, 'Early finish preserves the achieved coverage metric');
assertEqual(1, $earlyFinishView['metrics']['deferred_patterns'] ?? null, 'Early finish records unresolved patterns as deferred');
assertEqual(5, $earlyFinishView['metrics']['deferred_transactions'] ?? null, 'Early finish reports transactions left unchanged');
assertEqual(0, $earlyFinishView['metrics']['pending_patterns'] ?? null, 'A frozen early-finish run has no active AI queue');
assertEqual('excluded', $db->query("SELECT status FROM tag_taxonomy_patterns WHERE run_id=$earlyFinishRunId AND proposal_id IS NULL")->fetchColumn(), 'Deferred patterns are excluded from later cutover');
assertEqual($earlyFinishLiveState, $db->query("SELECT COUNT(*) AS tags, (SELECT COUNT(*) FROM tag_aliases) AS aliases, (SELECT COUNT(*) FROM transactions WHERE tag_id IS NOT NULL) AS tagged_transactions FROM tags")->fetch(PDO::FETCH_ASSOC), 'Early finish does not modify the live taxonomy or transaction assignments');

// Direction-aware aliases may safely reuse the same wording for money leaving
// and arriving, while Phase 3 must apply and roll back as one reconciled unit.
$outgoingDirectionTag = Tag::create('Direction outgoing test');
$incomingDirectionTag = Tag::create('Direction incoming test');
TagAlias::create($outgoingDirectionTag, 'DIRECTION SHARED MERCHANT', 'contains', true, 'manual', null, 1, 'outgoing');
TagAlias::create($incomingDirectionTag, 'DIRECTION SHARED MERCHANT', 'contains', true, 'manual', null, 1, 'incoming');
Tag::clearMatchCaches();
assertEqual($outgoingDirectionTag, Tag::findMatch('DIRECTION SHARED MERCHANT 123', -10), 'Outgoing alias rules match money leaving');
assertEqual($incomingDirectionTag, Tag::findMatch('DIRECTION SHARED MERCHANT 123', 10), 'Incoming alias rules match money arriving');
assertEqual(null, Tag::findMatch('DIRECTION SHARED MERCHANT 123'), 'Direction-specific rules do not guess when amount direction is unavailable');

$insertSafetyTransaction->execute([$safetyAccountId, '2026-08-24', -8.00, 'CUTOVER SHARED 111111', null, null, null, $outgoingDirectionTag, null, null]);
$cutoverOutgoingId = (int)$db->lastInsertId();
$insertSafetyTransaction->execute([$safetyAccountId, '2026-08-24', 8.00, 'CUTOVER SHARED 222222', null, null, null, $incomingDirectionTag, null, null]);
$cutoverIncomingId = (int)$db->lastInsertId();
$insertSafetyTransaction->execute([$safetyAccountId, '2026-08-24', -7.00, 'CUTOVER DEFERRED 333333', null, null, null, $outgoingDirectionTag, null, null]);
$cutoverDeferredId = (int)$db->lastInsertId();
for ($cutoverIndex = 1; $cutoverIndex <= 20; $cutoverIndex++) {
    $insertSafetyTransaction->execute([$safetyAccountId, '2026-08-24', -1.00, 'CUTOVER BULK ' . (400000 + $cutoverIndex), null, null, null, $outgoingDirectionTag, null, null]);
}
$cutoverRun = $migrationSafety->createSnapshot('Phase 3 cutover baseline', 'test-suite');
$cutoverRunId = (int)$cutoverRun['id'];
$taxonomyService->prepare($cutoverRunId);
$db->exec("INSERT INTO tag_taxonomy_proposals (run_id, canonical_name, canonical_name_normalized, description, category_id, confidence, status, origin, pattern_count, transaction_count, absolute_amount, reviewed_by, reviewed_at) VALUES ($cutoverRunId, 'Cutover canonical test', 'cutover canonical test', 'Reviewed Phase 3 destination', $taxonomyCategoryId, 0.96, 'approved', 'manual', 1, 1, 1, 'test-suite', CURRENT_TIMESTAMP)");
$cutoverProposalId = (int)$db->lastInsertId();
$deferredPatternId = (int)$db->query("SELECT pattern_id FROM transaction_tag_proposals WHERE run_id=$cutoverRunId AND transaction_id=$cutoverDeferredId")->fetchColumn();
$db->exec("UPDATE tag_taxonomy_patterns SET proposal_id=$cutoverProposalId, status='proposed', confidence=0.96 WHERE run_id=$cutoverRunId");
$db->exec("UPDATE transaction_tag_proposals SET proposal_id=$cutoverProposalId, confidence=0.96 WHERE run_id=$cutoverRunId");
$db->exec("UPDATE tag_taxonomy_patterns SET proposal_id=NULL, status='excluded', confidence=NULL WHERE id=$deferredPatternId");
$db->exec("UPDATE transaction_tag_proposals SET proposal_id=NULL, confidence=NULL WHERE run_id=$cutoverRunId AND pattern_id=$deferredPatternId");
$proposalMetrics = $db->query("SELECT COUNT(*) AS patterns, COALESCE(SUM(transaction_count),0) AS transactions, COALESCE(SUM(absolute_amount),0) AS absolute_amount FROM tag_taxonomy_patterns WHERE run_id=$cutoverRunId AND proposal_id=$cutoverProposalId")->fetch(PDO::FETCH_ASSOC);
$db->exec("UPDATE tag_taxonomy_proposals SET pattern_count=" . (int)$proposalMetrics['patterns'] . ", transaction_count=" . (int)$proposalMetrics['transactions'] . ", absolute_amount=" . (float)$proposalMetrics['absolute_amount'] . " WHERE id=$cutoverProposalId");
$taxonomyService->markReady($cutoverRunId, false);
$db->exec("UPDATE transactions SET transfer_id=777001 WHERE id=$cutoverOutgoingId");
$insertSafetyTransaction->execute([$safetyAccountId, '2026-08-24', -4.00, 'CUTOVER POST SNAPSHOT', null, null, null, $outgoingDirectionTag, null, null]);
$cutoverPostSnapshotId = (int)$db->lastInsertId();
$cutoverPostSnapshotTag = (int)$db->query("SELECT tag_id FROM transactions WHERE id=$cutoverPostSnapshotId")->fetchColumn();
$cutoverDeferredTag = (int)$db->query("SELECT tag_id FROM transactions WHERE id=$cutoverDeferredId")->fetchColumn();
$cutoverProtectedTag = (int)$db->query("SELECT tag_id FROM transactions WHERE id=$cutoverOutgoingId")->fetchColumn();
$cutoverFinancialBefore = $db->query('SELECT COUNT(*) AS rows_count, SUM(amount) AS total FROM transactions')->fetch(PDO::FETCH_ASSOC);
$cutoverService = new TagTaxonomyCutoverService($db);
assertEqual(true, $cutoverService->schemaReady(), 'Taxonomy cutover detects direction and audit schema');
$cutoverPreview = $cutoverService->preview($cutoverRunId);
assertEqual(true, $cutoverPreview['can_apply'], 'A fully reviewed and reconciled taxonomy is available for cutover');
assertEqual(true, (int)$cutoverPreview['metrics']['newly_protected_transactions'] >= 1, 'Cutover rechecks transactions that became protected after snapshot');
$cutoverResult = $cutoverService->apply($cutoverRunId, 'test-suite');
$cutoverCanonicalId = (int)$db->query("SELECT id FROM tags WHERE name_normalized='cutover canonical test'")->fetchColumn();
assertEqual('applied', $cutoverResult['status'], 'Phase 3 applies the reviewed taxonomy atomically');
assertEqual($cutoverCanonicalId, (int)$db->query("SELECT tag_id FROM transactions WHERE id=$cutoverIncomingId")->fetchColumn(), 'Cutover applies the approved canonical tag to covered eligible transactions');
assertEqual($cutoverProtectedTag, (int)$db->query("SELECT tag_id FROM transactions WHERE id=$cutoverOutgoingId")->fetchColumn(), 'Cutover leaves a newly confirmed transfer unchanged');
assertEqual($cutoverDeferredTag, (int)$db->query("SELECT tag_id FROM transactions WHERE id=$cutoverDeferredId")->fetchColumn(), 'Cutover leaves explicitly deferred patterns unchanged');
assertEqual($cutoverPostSnapshotTag, (int)$db->query("SELECT tag_id FROM transactions WHERE id=$cutoverPostSnapshotId")->fetchColumn(), 'Cutover leaves post-snapshot transactions unchanged');
assertEqual(2, (int)$db->query("SELECT COUNT(DISTINCT direction) FROM tag_aliases WHERE tag_id=$cutoverCanonicalId AND direction IN ('incoming','outgoing')")->fetchColumn(), 'Cutover installs independent incoming and outgoing alias rules');
assertEqual($cutoverFinancialBefore, $db->query('SELECT COUNT(*) AS rows_count, SUM(amount) AS total FROM transactions')->fetch(PDO::FETCH_ASSOC), 'Cutover preserves the financial ledger fingerprint');

// The optional aggressive cleanup retires every noncanonical legacy choice,
// even when deferred/newer history still references it. It must not retag or
// delete that history, and the original cutover rollback must restore the
// exact legacy tag and alias state as well as the reviewed classifications.
$legacyHistoryTag = Tag::create('Legacy deferred history test', null, 'Retained only for historical display', 'legacy');
$legacyHistoryAlias = TagAlias::create($legacyHistoryTag, 'LEGACY DEFERRED HISTORY', 'contains', true, 'legacy', null, 1, 'outgoing');
$protectedSystemTag = Tag::create('Protected cleanup system test', null, null, 'system');
$insertSafetyTransaction->execute([$safetyAccountId, '2026-08-24', -3.00, 'LEGACY DEFERRED HISTORY 999999', null, null, null, $legacyHistoryTag, null, null]);
$legacyHistoryTransactionId = (int)$db->lastInsertId();
$cleanupFinancialBefore = $db->query('SELECT COUNT(*) AS rows_count, SUM(amount) AS total FROM transactions')->fetch(PDO::FETCH_ASSOC);
$db->exec("UPDATE transactions SET segment_id=777002 WHERE id=$cutoverIncomingId");
assertEqual(false, $cutoverService->rollbackPreview($cutoverRunId)['can_rollback'], 'Later classification work can correctly make the full cutover rollback unavailable');
$cleanupPreview = $cutoverService->legacyCleanupPreview($cutoverRunId);
assertEqual(true, $cleanupPreview['can_cleanup'], 'Later classification work does not block the independent legacy catalogue cleanup');
assertEqual(true, (int)$cleanupPreview['metrics']['tags_to_deprecate'] >= 1, 'Legacy cleanup previews every noncanonical legacy tag');
assertEqual(true, (int)$cleanupPreview['metrics']['transactions_retaining_history'] >= 1, 'Legacy cleanup reports historical transaction assignments it will retain');
$cleanupResult = $cutoverService->cleanupLegacy($cutoverRunId, 'test-suite');
assertEqual('legacy_cleaned', $cleanupResult['status'], 'Aggressive legacy catalogue cleanup completes atomically');
assertEqual('deprecated', $db->query("SELECT status FROM tags WHERE id=$legacyHistoryTag")->fetchColumn(), 'Referenced noncanonical legacy tag is deprecated');
assertEqual(0, (int)$db->query("SELECT active FROM tag_aliases WHERE id=$legacyHistoryAlias")->fetchColumn(), 'Legacy matching alias is disabled');
assertEqual($legacyHistoryTag, (int)$db->query("SELECT tag_id FROM transactions WHERE id=$legacyHistoryTransactionId")->fetchColumn(), 'Historical transaction keeps its original legacy tag id');
assertEqual(777002, (int)$db->query("SELECT segment_id FROM transactions WHERE id=$cutoverIncomingId")->fetchColumn(), 'Legacy cleanup preserves a classification changed after cutover');
assertEqual('active', $db->query("SELECT status FROM tags WHERE id=$cutoverCanonicalId")->fetchColumn(), 'Reviewed canonical tag remains active');
assertEqual('active', $db->query("SELECT status FROM tags WHERE id=$protectedSystemTag")->fetchColumn(), 'Genuine system tag remains active');
assertEqual('active', $db->query('SELECT status FROM tags WHERE id=' . Tag::getIgnoreId())->fetchColumn(), 'IGNORE remains active');
assertEqual(0, (int)$db->query("SELECT COUNT(*) FROM tags WHERE status='active' AND origin='legacy' AND UPPER(TRIM(name)) <> 'IGNORE' AND id <> $cutoverCanonicalId")->fetchColumn(), 'No noncanonical legacy tag remains available for future use');
assertEqual(false, in_array($legacyHistoryTag, array_map('intval', array_column(Tag::all(), 'id')), true), 'Deprecated legacy tags disappear from management pickers');
$postCleanupCorrectionTags = $correctionService->tagContext();
try {
    $correctionService->createPlan(
        'Move Legacy deferred history test to itself',
        ['source_tag_ids' => [$legacyHistoryTag], 'target_tag_id' => $legacyHistoryTag, 'target_tag_name' => 'Legacy deferred history test', 'match_terms' => [], 'confidence' => 0.99],
        $postCleanupCorrectionTags
    );
    $retiredTargetRejected = false;
} catch (InvalidArgumentException $e) {
    $retiredTargetRejected = strpos($e->getMessage(), 'retired destination') !== false;
}
assertEqual(true, $retiredTargetRejected, 'AI correction can recognise retired history but refuses a retired destination tag');
assertEqual($cleanupFinancialBefore, $db->query('SELECT COUNT(*) AS rows_count, SUM(amount) AS total FROM transactions')->fetch(PDO::FETCH_ASSOC), 'Legacy cleanup preserves the financial ledger');
assertEqual(true, $cutoverService->legacyCleanupPreview($cutoverRunId)['completed'], 'Legacy cleanup is recorded in the cutover audit');
$db->exec("UPDATE transactions SET segment_id=NULL WHERE id=$cutoverIncomingId");
assertEqual(true, $cutoverService->rollbackPreview($cutoverRunId)['can_rollback'], 'An unchanged audited cutover is safely reversible');
$cutoverRollback = $cutoverService->rollback($cutoverRunId, 'test-suite');
assertEqual('rolled_back', $cutoverRollback['status'], 'Phase 3 rollback restores the audited taxonomy state');
assertEqual($incomingDirectionTag, (int)$db->query("SELECT tag_id FROM transactions WHERE id=$cutoverIncomingId")->fetchColumn(), 'Rollback restores the original transaction classification');
assertEqual(0, (int)$db->query("SELECT COUNT(*) FROM tags WHERE id=$cutoverCanonicalId")->fetchColumn(), 'Rollback removes a cutover-created tag when it is no longer referenced');
assertEqual($cutoverPostSnapshotTag, (int)$db->query("SELECT tag_id FROM transactions WHERE id=$cutoverPostSnapshotId")->fetchColumn(), 'Rollback still leaves post-snapshot transactions untouched');
assertEqual('active', $db->query("SELECT status FROM tags WHERE id=$legacyHistoryTag")->fetchColumn(), 'Rollback restores a cleanup-retired legacy tag');
assertEqual(1, (int)$db->query("SELECT active FROM tag_aliases WHERE id=$legacyHistoryAlias")->fetchColumn(), 'Rollback restores a cleanup-disabled legacy alias');

// Excel is a curated workbook rather than another raw transaction dump. Keep
// excluded ledger evidence visible while reconciling every analytical total.
$db->exec("INSERT INTO accounts (name, sort_code, account_number) VALUES ('Workbook account', '00-00-00', '99990000')");
$workbookAccountId = (int)$db->lastInsertId();
$db->exec("INSERT INTO segments (name, description) VALUES ('Workbook fixed costs', 'Export test')");
$workbookSegmentId = (int)$db->lastInsertId();
$db->exec("INSERT INTO categories (name, description, segment_id) VALUES ('Workbook housing', 'Export test', $workbookSegmentId)");
$workbookCategoryId = (int)$db->lastInsertId();
$db->exec("INSERT INTO tags (name, name_normalized, description, origin, status) VALUES ('Workbook mortgage', 'workbook mortgage', 'Export test', 'reviewed', 'active')");
$workbookTagId = (int)$db->lastInsertId();
$db->exec("INSERT INTO transaction_groups (name, description, active) VALUES ('Workbook household', 'Export test', 1)");
$workbookGroupId = (int)$db->lastInsertId();
$workbookInsert = $db->prepare('INSERT INTO transactions (account_id, date, amount, description, memo, category_id, segment_id, tag_id, group_id, transfer_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$workbookInsert->execute([$workbookAccountId, '2031-01-02', 3000.00, 'Salary', null, null, null, null, null, null]);
$workbookInsert->execute([$workbookAccountId, '2031-01-05', -120.00, 'Mortgage payment', 'Monthly home cost', $workbookCategoryId, $workbookSegmentId, $workbookTagId, $workbookGroupId, null]);
$workbookInsert->execute([$workbookAccountId, '2031-01-07', -500.00, 'Move to savings', null, null, null, null, null, null]);
$workbookTransferId = (int)$db->lastInsertId();
$db->exec("UPDATE transactions SET transfer_id=$workbookTransferId WHERE id=$workbookTransferId");
$workbookInsert->execute([$workbookAccountId, '2031-01-09', -50.00, 'Ignored correction', null, null, null, Tag::getIgnoreId(), null, null]);

$workbookService = new FinancialWorkbookExportService($db);
$workbookData = $workbookService->build('2031-01-01', '2031-01-31');
assertEqual(4, count($workbookData['transactions']), 'Excel ledger retains every transaction in the selected period');
assertEqual(3000.0, $workbookData['metrics']['income'], 'Excel summary includes ordinary income');
assertEqual(120.0, $workbookData['metrics']['spending'], 'Excel summary excludes transfers and ignored rows from spending');
assertEqual(2880.0, $workbookData['metrics']['net'], 'Excel summary reconciles period net movement');
assertEqual(500.0, $workbookData['metrics']['transfer_moved'], 'Excel summary reports internal money moved separately');
assertEqual(1, $workbookData['metrics']['ignored_count'], 'Excel ledger identifies ignored rows');
assertEqual('Workbook fixed costs', key($workbookData['segments']), 'Excel analysis ranks spending by segment');
try {
    FinancialWorkbookExportService::validateRange('2031-02-01', '2031-01-01');
    $reversedWorkbookPeriodRejected = false;
} catch (InvalidArgumentException $e) {
    $reversedWorkbookPeriodRejected = true;
}
assertEqual(true, $reversedWorkbookPeriodRejected, 'Excel export rejects a reversed date range');

$workbookFile = tempnam(sys_get_temp_dir(), 'accounts-workbook-test-');
$workbookService->createWorkbook('2031-01-01', '2031-01-31', $workbookFile);
assertEqual("PK", substr((string)file_get_contents($workbookFile), 0, 2), 'Excel export creates a valid OOXML zip container');
$workbookZip = new ZipArchive();
$workbookZip->open($workbookFile);
$workbookXml = (string)$workbookZip->getFromName('xl/workbook.xml');
$summaryXml = (string)$workbookZip->getFromName('xl/worksheets/sheet1.xml');
$transactionsXml = (string)$workbookZip->getFromName('xl/worksheets/sheet3.xml');
assertEqual(3, substr_count($workbookXml, '<sheet '), 'Excel workbook contains Summary, Pivot Analysis and Transactions sheets');
assertEqual(true, strpos(html_entity_decode($summaryXml, ENT_QUOTES | ENT_XML1, 'UTF-8'), "SUM('Transactions'!\$K\$6") !== false, 'Excel summary formulas remain linked to the transaction ledger');
assertEqual(true, $workbookZip->locateName('xl/tables/table1.xml') !== false, 'Excel transaction ledger is a filterable structured table');
assertEqual(true, strpos($transactionsXml, 'Transfer') !== false && strpos($transactionsXml, 'Ignored') !== false, 'Excel ledger explains excluded transfer and ignored rows');
$workbookZip->close();
unlink($workbookFile);
$db->exec("DELETE FROM transactions WHERE account_id=$workbookAccountId");
$db->exec("DELETE FROM transaction_groups WHERE id=$workbookGroupId");
$db->exec("DELETE FROM tags WHERE id=$workbookTagId");
$db->exec("DELETE FROM categories WHERE id=$workbookCategoryId");
$db->exec("DELETE FROM segments WHERE id=$workbookSegmentId");
$db->exec("DELETE FROM accounts WHERE id=$workbookAccountId");

// Static page shells and their local code/style assets must be revalidated so
// a deployment cannot leave users with a mixture of old and new UI files.
$frontendCachePolicy = file_get_contents(__DIR__ . '/../frontend/.htaccess');
assertEqual(true, strpos($frontendCachePolicy, '\\.(?:html?|php)$') !== false, 'Cache policy covers HTML and PHP page shells');
assertEqual(true, strpos($frontendCachePolicy, '\\.(?:css|js|json|map|webmanifest)$') !== false, 'Cache policy covers local code and style assets');
assertEqual(true, strpos($frontendCachePolicy, 'Header always set Cache-Control') !== false, 'Cache policy applies headers to every response status');
$staticPagesMissingCacheMeta = [];
foreach (glob(__DIR__ . '/../frontend/*.html') as $staticPage) {
    if (strpos((string)file_get_contents($staticPage), 'http-equiv="Cache-Control"') === false) {
        $staticPagesMissingCacheMeta[] = basename($staticPage);
    }
}
assertEqual([], $staticPagesMissingCacheMeta, 'Every static page includes a cache-control fallback');

$settingsMarkup = (string)file_get_contents(__DIR__ . '/../settings.php');
foreach (['surface_style', 'interface_density', 'corner_style', 'backdrop_strength', 'motion_preference', 'accent_bar_size', 'page_header_size'] as $appearanceField) {
    assertEqual(true, strpos($settingsMarkup, 'name="' . $appearanceField . '"') !== false, 'Settings page exposes ' . $appearanceField);
}
$menuScript = (string)file_get_contents(__DIR__ . '/../frontend/js/menu.js');
assertEqual(true, strpos($menuScript, 'applyAppearancePreferences') !== false, 'Shared application shell applies appearance settings');
assertEqual(true, strpos($menuScript, 'interface-preferences.css') !== false, 'Shared application shell loads appearance preference styles');
assertEqual(true, strpos($menuScript, "setProperty('--site-brand'") !== false, 'Shared application shell propagates the selected palette through site variables');
assertEqual(true, strpos($settingsMarkup, 'settings-palette-grid') !== false, 'Settings page presents colour choices as visible palette swatches');
assertEqual(true, strpos($settingsMarkup, '<optgroup') !== false, 'Settings page groups the expanded font catalogue for easier selection');
$fontScript = (string)file_get_contents(__DIR__ . '/../frontend/js/fonts.js');
assertEqual(true, strpos($fontScript, "'Atkinson Hyperlegible': '400;700'") !== false, 'Font loading requests only supported Atkinson weights');
assertEqual(false, strpos($settingsMarkup, 'fontChoices.forEach') !== false, 'Settings page no longer downloads every available web font');
$preferenceStyles = (string)file_get_contents(__DIR__ . '/../frontend/css/interface-preferences.css');
assertEqual(true, strpos($preferenceStyles, 'ui-accent-bar-hairline') !== false, 'Appearance styles include the hairline top accent option');
assertEqual(true, strpos($preferenceStyles, 'ui-accent-reveal') !== false, 'Primary top accents use the shared reveal animation');
$heroDensityScript = (string)file_get_contents(__DIR__ . '/../frontend/js/menu.js');
$heroDensityStyles = (string)file_get_contents(__DIR__ . '/../frontend/css/hero-density.css');
assertEqual(true, strpos($heroDensityScript, 'hero-density.css') !== false, 'Shared application shell loads compact dashboard hero styles');
assertEqual(true, strpos($heroDensityStyles, 'min-height:0!important') !== false, 'Dashboard heroes do not reserve decorative empty height');
assertEqual(true, strpos($heroDensityStyles, '.instant-position') !== false, 'Headline hero content uses the shared compact financial brief layout');
$paperStyles = (string)file_get_contents(__DIR__ . '/../frontend/css/theme-professional.css');
assertEqual(true, strpos($paperStyles, '--paper-canvas') !== false, 'Paper view defines its own document canvas');
assertEqual(true, strpos($paperStyles, 'border-top:1px solid var(--paper-rule)') !== false, 'Paper sections use horizontal rules instead of card outlines');
assertEqual(true, strpos($paperStyles, 'background:transparent!important') !== false, 'Paper content surfaces flatten into the shared sheet');
$trendStyles = (string)file_get_contents(__DIR__ . '/../frontend/financial_trends.css');
assertEqual(true, strpos($trendStyles, '.trends-controls::before { inset:0 0 auto; width:100%') !== false, 'Trends period controls use a full-width top accent');

// Keep Safari and iOS Password AutoFill on the correct credential type at
// each stage of sign-in. In particular, focusing the TOTP field as soon as the
// second step renders can reopen the password chooser instead of offering a
// saved verification code.
$loginMarkup = (string)file_get_contents(__DIR__ . '/../index.php');
preg_match('/<form[^>]+id="token-form"[^>]*>(.*?)<\/form>/s', $loginMarkup, $tokenFormMatch);
$tokenFormMarkup = $tokenFormMatch[0] ?? '';
assertEqual(true, strpos($tokenFormMarkup, 'autocomplete="one-time-code"') !== false, 'Login verification field advertises one-time-code AutoFill');
assertEqual(false, strpos($tokenFormMarkup, 'autofocus') !== false, 'Login verification field does not automatically reopen credential AutoFill');
assertEqual(false, strpos($tokenFormMarkup, 'current-password') !== false, 'Login verification form contains no password AutoFill signal');
preg_match('/<form[^>]+id="login-form"[^>]*>(.*?)<\/form>/s', $loginMarkup, $credentialFormMatch);
$credentialFormMarkup = $credentialFormMatch[0] ?? '';
assertEqual(true, strpos($credentialFormMarkup, 'autocomplete="username webauthn"') !== false, 'Login username combines username and conditional passkey AutoFill');
assertEqual(true, strpos($credentialFormMarkup, 'autocomplete="current-password"') !== false, 'Login password retains password AutoFill');
assertEqual(true, strpos($loginMarkup, 'id="passkey-login-button"') !== false, 'Login page offers passkey authentication alongside the password flow');
assertEqual(true, strpos($loginMarkup, 'passkey_login.js') !== false, 'Login page loads the shared WebAuthn client flow');
assertEqual(true, strpos((string)file_get_contents(__DIR__ . '/../frontend/js/passkey_login.js'), "mediation = 'conditional'") !== false, 'Login starts conditional passkey discovery without exposing credential identity');
$userManagementMarkup = (string)file_get_contents(__DIR__ . '/../users.php');
assertEqual(true, strpos($userManagementMarkup, 'id="passkey-manager"') !== false, 'User Management provides passkey enrolment and removal');

$navigationMarkup = (string)file_get_contents(__DIR__ . '/../frontend/menu.php');
preg_match_all('/href="([a-z0-9_\-]+\.html)"/i', $navigationMarkup, $navigationMatches);
$navigationPagesMissingModernHeader = [];
foreach (array_unique($navigationMatches[1] ?? []) as $navigationPage) {
    if ($navigationPage === 'index.html') continue; // The landing page has its own hero system.
    $pageMarkup = (string)file_get_contents(__DIR__ . '/../frontend/' . $navigationPage);
    if (strpos($pageMarkup, 'renderPageHeader') === false) {
        $navigationPagesMissingModernHeader[] = $navigationPage;
    }
}
assertEqual([], $navigationPagesMissingModernHeader, 'Every active navigation page uses the modern page header');

// Backup/restore must round-trip current security and reporting data and must
// leave the original database untouched when post-restore integrity fails.
$db->exec("INSERT OR REPLACE INTO totp_secrets (username, secret) VALUES ('alice', 'TEST-TOTP-SECRET')");
$db->exec("INSERT INTO saved_reports (name, description, filters) VALUES ('Backup report', 'Round trip', '{\"start\":\"2024-01-01\"}')");
$_GET['parts'] = 'transactions,categories,tags,groups,budgets,segments,settings,reports,tag_migrations';
$_SERVER['HTTP_HOST'] = 'test.local';
ob_start();
include __DIR__ . '/../php_backend/public/backup.php';
$backupArchive = ob_get_clean();
$backupSignature = strpos($backupArchive, "\x1f\x8b");
$backupJson = $backupSignature === false ? false : gzdecode(substr($backupArchive, $backupSignature));
$backupPayload = json_decode($backupJson, true);
assertEqual(null, $backupPayload['error'] ?? null, 'Backup generation completes without a database error');
assertEqual('newaccounts-backup', $backupPayload['_meta']['format'] ?? null, 'Backup includes a format manifest');
assertEqual(6, $backupPayload['_meta']['version'] ?? null, 'Backup includes the current format version');
assertEqual(true, array_key_exists('direction', $backupPayload['tag_aliases'][0] ?? []), 'Backup preserves direction-aware tag rules');
assertEqual(true, array_key_exists('cutover_summary', $backupPayload['tag_migration_runs'][0] ?? []), 'Backup preserves taxonomy cutover audit fields');
assertEqual(1, count($backupPayload['totp_secrets'] ?? []), 'Backup includes two-factor authentication state');
assertEqual(1, count($backupPayload['passkeys'] ?? []), 'Backup includes passkey public credentials');
assertEqual(true, count($backupPayload['saved_reports'] ?? []) >= 1, 'Backup includes saved reports');
assertEqual(true, count($backupPayload['tag_migration_runs'] ?? []) >= 1, 'Backup includes tag rebuild safety runs');
assertEqual(true, count($backupPayload['transaction_classification_snapshots'] ?? []) >= 1, 'Backup includes immutable classification snapshots');
assertEqual(true, count($backupPayload['tag_taxonomy_proposals'] ?? []) >= 1, 'Backup includes reviewed taxonomy proposals');
assertEqual(true, count($backupPayload['tag_taxonomy_patterns'] ?? []) >= 1, 'Backup includes staged transaction patterns');
assertEqual(true, count($backupPayload['transaction_tag_proposals'] ?? []) >= 1, 'Backup includes staged transaction assignments');

$db->exec('DELETE FROM totp_secrets');
$db->exec('DELETE FROM passkeys');
$db->exec('DELETE FROM saved_reports');
$backupFile = tempnam(sys_get_temp_dir(), 'accounts-backup-');
file_put_contents($backupFile, $backupArchive);
$_FILES['backup_file'] = ['error' => UPLOAD_ERR_OK, 'tmp_name' => $backupFile];
ob_start();
include __DIR__ . '/../php_backend/public/restore.php';
$restoreMessage = ob_get_clean();
$db = Database::getConnection();
assertEqual('Restore complete.', $restoreMessage, 'A complete backup restores successfully');
assertEqual('TEST-TOTP-SECRET', $db->query("SELECT secret FROM totp_secrets WHERE username='alice'")->fetchColumn(), 'Restore preserves two-factor authentication state');
assertEqual('Test Mac', $db->query("SELECT label FROM passkeys WHERE user_id=1")->fetchColumn(), 'Restore preserves passkey credentials');
assertEqual(1, (int)$db->query("SELECT COUNT(*) FROM saved_reports WHERE name='Backup report'")->fetchColumn(), 'Restore preserves saved reports');
assertEqual(true, (int)$db->query('SELECT COUNT(*) FROM tag_migration_runs')->fetchColumn() >= 1, 'Restore preserves tag rebuild safety history');
assertEqual(true, (int)$db->query('SELECT COUNT(*) FROM tag_taxonomy_proposals')->fetchColumn() >= 1, 'Restore preserves reviewed taxonomy proposals');
assertEqual(true, (int)$db->query('SELECT COUNT(*) FROM transaction_tag_proposals')->fetchColumn() >= 1, 'Restore preserves transaction-level staging coverage');

$transactionCountBeforeFailure = (int)$db->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
$failedPayload = $backupPayload;
$failedPayload['transactions'][] = [
    'id' => 999999, 'account_id' => 999999, 'date' => '2024-01-01', 'amount' => '-1.00',
    'description' => 'Broken account reference', 'memo' => null, 'category_id' => null,
    'segment_id' => null, 'tag_id' => null, 'group_id' => null, 'transfer_id' => null,
    'ofx_id' => 'broken-restore-test', 'ofx_type' => null, 'bank_ofx_id' => 'broken-restore-test',
];
$failedPayload['_meta']['counts']['transactions']++;
$failedArchive = gzencode(json_encode($failedPayload));
file_put_contents($backupFile, $failedArchive);
ob_start();
include __DIR__ . '/../php_backend/public/restore.php';
$failedRestoreMessage = ob_get_clean();
$db = Database::getConnection();
assertEqual(true, strpos($failedRestoreMessage, 'Restore integrity check failed') !== false, 'Invalid restore reports its integrity failure');
assertEqual($transactionCountBeforeFailure, (int)$db->query('SELECT COUNT(*) FROM transactions')->fetchColumn(), 'Failed restore rolls back the original transactions');
assertEqual(1, (int)$db->query("SELECT COUNT(*) FROM saved_reports WHERE name='Backup report'")->fetchColumn(), 'Failed restore rolls back the original saved reports');
unlink($backupFile);

// A deliberate fresh start keeps the controlled vocabulary and protected
// transactions, while clearing live classifications and learned structure only
// after an immutable safety snapshot has been created.
$freshSegmentId = Segment::create('Fresh start segment', 'Reset verification');
$freshCategoryId = Category::create('Fresh start category', 'Reset verification');
$db->prepare('UPDATE categories SET segment_id = :segment WHERE id = :category')->execute(['segment' => $freshSegmentId, 'category' => $freshCategoryId]);
$freshTagId = Tag::create('Fresh Start Canonical', 'fresh-start-keyword', 'Reset verification', 'manual');
CategoryTag::add($freshCategoryId, $freshTagId);
$freshIgnoreId = Tag::getIgnoreId();
$db->prepare("UPDATE tags SET keyword = 'IGNORE' WHERE id = :id")->execute(['id' => $freshIgnoreId]);
$freshRuleId = TagAlias::create($freshTagId, 'fresh-start-merchant', 'contains', true, 'ai', 0.99, 4, 'outgoing');
$freshIgnoreRuleId = TagAlias::create($freshIgnoreId, 'fresh-start-protected-ignore', 'exact', true, 'system', 1.0, 1, 'outgoing');
$freshAccountId = Account::create('Fresh start account', '00-00-00', 'fresh-start-account');
$freshInsert = $db->prepare('INSERT INTO transactions (account_id,date,amount,description,memo,tag_id,category_id,segment_id,transfer_id) VALUES (?,?,?,?,?,?,?,?,?)');
$freshInsert->execute([$freshAccountId, '2026-08-01', -12.00, 'fresh-start-merchant', null, $freshTagId, $freshCategoryId, $freshSegmentId, null]);
$freshEligibleTransaction = (int)$db->lastInsertId();
$freshInsert->execute([$freshAccountId, '2026-08-02', -5.00, 'fresh-start-partial', null, null, $freshCategoryId, $freshSegmentId, null]);
$freshPartialTransaction = (int)$db->lastInsertId();
$freshInsert->execute([$freshAccountId, '2026-08-03', -20.00, 'fresh-start-transfer', null, $freshTagId, $freshCategoryId, $freshSegmentId, 777]);
$freshTransferTransaction = (int)$db->lastInsertId();
$freshInsert->execute([$freshAccountId, '2026-08-04', -2.00, 'fresh-start-protected-ignore', null, $freshIgnoreId, null, null, null]);
$freshIgnoredTransaction = (int)$db->lastInsertId();

$freshService = new TaggingFreshStartService($db);
$freshPreview = $freshService->preview();
assertEqual(true, $freshPreview['classified_transactions'] >= 2, 'Fresh-start preview counts classifications that will be cleared');
assertEqual(true, $freshPreview['rules_to_remove'] >= 1, 'Fresh-start preview counts learned rules that will be removed');
assertEqual(true, $freshPreview['canonical_tags_retained'] >= 2, 'Fresh-start preview confirms the canonical vocabulary is retained');
$freshRejected = false;
try {
    $freshService->reset('RESET');
} catch (InvalidArgumentException $e) {
    $freshRejected = true;
}
assertEqual(true, $freshRejected, 'Fresh start rejects an incorrect confirmation phrase');

$freshResult = $freshService->reset(TaggingFreshStartService::CONFIRMATION, 'test-user');
$freshEligibleState = $db->query("SELECT tag_id,category_id,segment_id FROM transactions WHERE id=$freshEligibleTransaction")->fetch(PDO::FETCH_ASSOC);
$freshPartialState = $db->query("SELECT tag_id,category_id,segment_id FROM transactions WHERE id=$freshPartialTransaction")->fetch(PDO::FETCH_ASSOC);
$freshTransferState = $db->query("SELECT tag_id,category_id,segment_id FROM transactions WHERE id=$freshTransferTransaction")->fetch(PDO::FETCH_ASSOC);
$freshIgnoredState = $db->query("SELECT tag_id,category_id,segment_id FROM transactions WHERE id=$freshIgnoredTransaction")->fetch(PDO::FETCH_ASSOC);
assertEqual(['tag_id' => null, 'category_id' => null, 'segment_id' => null], $freshEligibleState, 'Fresh start clears tag, category and segment from eligible transactions');
assertEqual(['tag_id' => null, 'category_id' => null, 'segment_id' => null], $freshPartialState, 'Fresh start clears partial classifications as well as tagged transactions');
assertEqual($freshTagId, (int)$freshTransferState['tag_id'], 'Fresh start preserves confirmed transfer classifications');
assertEqual($freshCategoryId, (int)$freshTransferState['category_id'], 'Fresh start preserves confirmed transfer categories');
assertEqual($freshIgnoreId, (int)$freshIgnoredState['tag_id'], 'Fresh start preserves IGNORE transactions');
assertEqual(0, (int)$db->query("SELECT COUNT(*) FROM tag_aliases WHERE id=$freshRuleId")->fetchColumn(), 'Fresh start removes ordinary learned rules so AI can relearn without conflicts');
assertEqual(1, (int)$db->query("SELECT active FROM tag_aliases WHERE id=$freshIgnoreRuleId")->fetchColumn(), 'Fresh start preserves the protected IGNORE rule');
assertEqual(0, (int)$db->query("SELECT COUNT(*) FROM category_tags WHERE tag_id=$freshTagId")->fetchColumn(), 'Fresh start clears tag-to-category links');
assertEqual(null, $db->query("SELECT keyword FROM tags WHERE id=$freshTagId")->fetchColumn(), 'Fresh start clears legacy tag keywords');
assertEqual('active', $db->query("SELECT status FROM tags WHERE id=$freshTagId")->fetchColumn(), 'Fresh start retains canonical tags for the AI allowlist');
$freshRunId = (int)$freshResult['snapshot_run_id'];
$freshAudit = json_decode((string)$db->query("SELECT cutover_summary FROM tag_migration_runs WHERE id=$freshRunId")->fetchColumn(), true);
assertEqual(true, isset($freshAudit['fresh_start']['previous_rule_state'], $freshAudit['fresh_start']['previous_category_links']), 'Fresh start records the removed rule and category-link state in its audit');
$freshRollbackPreview = (new TagMigrationSafetyService($db))->rollbackPreview($freshRunId);
assertEqual(true, $freshRollbackPreview['restorable'], 'Fresh-start transaction classifications remain restorable from the hashed snapshot');
assertEqual(true, $freshRollbackPreview['changed_transactions'] >= 2, 'Fresh-start snapshot records the classifications changed by the reset');
$db->prepare('UPDATE transactions SET category_id = NULL WHERE id = :id')->execute(['id' => $freshTransferTransaction]);
CategoryTag::assign($freshCategoryId, $freshTagId);
assertEqual(null, $db->query("SELECT category_id FROM transactions WHERE id=$freshTransferTransaction")->fetch(PDO::FETCH_ASSOC)['category_id'], 'Category assignment does not rewrite a confirmed transfer after a fresh start');
CategoryTag::applyToAllTransactions();
assertEqual(null, $db->query("SELECT category_id FROM transactions WHERE id=$freshTransferTransaction")->fetch(PDO::FETCH_ASSOC)['category_id'], 'Category propagation keeps confirmed transfers protected after AI tagging');

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
