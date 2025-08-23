<?php
require_once __DIR__ . '/../php_backend/Database.php';
require_once __DIR__ . '/../php_backend/models/Tag.php';

putenv('DB_DSN=sqlite::memory:');
$db = Database::getConnection();

function check($condition, $message) {
    if (!$condition) {
        throw new Exception($message);
    }
}

// minimal schema for tag deletion
$db->exec('CREATE TABLE tags (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, keyword TEXT, description TEXT)');
$db->exec('CREATE TABLE category_tags (category_id INTEGER, tag_id INTEGER)');
$db->exec('CREATE TABLE transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, tag_id INTEGER)');

// seed two tags with linked transactions and category assignment
$db->exec("INSERT INTO tags (name) VALUES ('A'), ('B')");
$db->exec("INSERT INTO category_tags (category_id, tag_id) VALUES (1,1), (2,2)");
$db->exec("INSERT INTO transactions (tag_id) VALUES (1), (2), (2)");

// delete second tag
Tag::delete(2);

$tags = $db->query('SELECT id FROM tags')->fetchAll(PDO::FETCH_COLUMN);
$tags = array_map('intval', $tags);
check($tags === [1], 'Only tag 1 should remain');

$txTags = $db->query('SELECT tag_id FROM transactions ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$txTags = array_map(fn($v) => $v === null ? null : (int)$v, $txTags);
check($txTags === [1, null, null], 'Transactions with deleted tag should be cleared');

$catTags = $db->query('SELECT tag_id FROM category_tags ORDER BY category_id')->fetchAll(PDO::FETCH_COLUMN);
$catTags = array_map('intval', $catTags);
check($catTags === [1], 'Category tag link for deleted tag should be removed');

echo "Tag deletion test passed\n";
