<?php
putenv('DB_DSN=sqlite::memory:');
require_once __DIR__ . '/../php_backend/models/Category.php';
$db = Database::getConnection();
$db->exec('CREATE TABLE tags (id INTEGER PRIMARY KEY, name TEXT, keyword TEXT, description TEXT, status TEXT)');
$db->exec('CREATE TABLE transactions (id INTEGER PRIMARY KEY, tag_id INTEGER, amount NUMERIC, transfer_id INTEGER)');
$db->exec("INSERT INTO tags (id,name,status) VALUES (1,'Mixed','active'), (2,'Unused','active'), (3,'Retired','retired'), (4,'IGNORE','active')");
$db->exec('INSERT INTO transactions VALUES (1,1,-100,NULL), (2,1,25,NULL), (3,1,-5,9), (4,1,0,NULL), (5,3,-999,NULL), (6,NULL,-77,NULL)');
$rows = array_column(Category::assignmentTags(), null, 'id');
if (count($rows) !== 2 || (int)$rows[1]['transaction_count'] !== 4
    || (float)$rows[1]['outgoing'] !== 105.0 || (float)$rows[1]['incoming'] !== 25.0
    || (float)$rows[1]['total_value'] !== 130.0 || (int)$rows[2]['transaction_count'] !== 0
    || (float)$rows[2]['total_value'] !== 0.0) {
    throw new RuntimeException('Category assignment usage totals are incorrect');
}
echo "Category assignment usage tests passed.\n";
