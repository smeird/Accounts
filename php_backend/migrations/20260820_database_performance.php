<?php
// Remove duplicate legacy foreign keys and refresh post-migration statistics.
require_once __DIR__ . '/../Database.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$db = Database::getConnection();
$changes = [];

$foreignKeys = [
    'transactions' => [
        'transactions_ibfk_1' => 'fk_transactions_account',
        'transactions_ibfk_2' => 'fk_transactions_category',
        'transactions_ibfk_3' => 'fk_transactions_segment',
        'transactions_ibfk_4' => 'fk_transactions_tag',
        'transactions_ibfk_5' => 'fk_transactions_group',
    ],
    'categories' => ['categories_ibfk_1' => 'fk_categories_segment'],
    'budgets' => ['budgets_ibfk_1' => 'fk_budgets_category'],
    'category_tags' => [
        'category_tags_ibfk_1' => 'fk_category_tags_category',
        'category_tags_ibfk_2' => 'fk_category_tags_tag',
    ],
    'segment_categories' => [
        'segment_categories_ibfk_1' => 'fk_segment_categories_segment',
        'segment_categories_ibfk_2' => 'fk_segment_categories_category',
    ],
];

$constraintExists = $db->prepare(
    'SELECT COUNT(*) FROM information_schema.referential_constraints '
    . 'WHERE constraint_schema = DATABASE() AND table_name = :table_name '
    . 'AND constraint_name = :constraint_name'
);

foreach ($foreignKeys as $table => $duplicates) {
    foreach ($duplicates as $legacy => $canonical) {
        $constraintExists->execute(['table_name' => $table, 'constraint_name' => $legacy]);
        $legacyExists = (int)$constraintExists->fetchColumn() > 0;
        $constraintExists->execute(['table_name' => $table, 'constraint_name' => $canonical]);
        $canonicalExists = (int)$constraintExists->fetchColumn() > 0;
        if ($legacyExists && $canonicalExists) {
            $db->exec("ALTER TABLE `$table` DROP FOREIGN KEY `$legacy`");
            $changes[] = "removed duplicate $table.$legacy";
        }
    }
}

$index = $db->query(
    "SHOW INDEX FROM `transactions` WHERE Key_name = 'idx_transactions_account_date_id'"
);
if ($index->rowCount() === 0) {
    $db->exec(
        'ALTER TABLE `transactions` ADD INDEX `idx_transactions_account_date_id` '
        . '(`account_id`, `date`, `id`)'
    );
    $changes[] = 'added idx_transactions_account_date_id';
}

foreach (['transactions', 'logs', 'tags', 'tag_aliases', 'category_tags'] as $table) {
    $db->exec("ANALYZE TABLE `$table`");
}
$changes[] = 'refreshed optimizer statistics';

echo implode("\n", $changes) . "\n";
