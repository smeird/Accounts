<?php
require_once __DIR__ . '/../php_backend/models/User.php';

// Use an in-memory SQLite database for tests.
putenv('DB_DSN=sqlite::memory:');
$db = Database::getConnection();
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, password TEXT);');

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
