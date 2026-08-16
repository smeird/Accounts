<?php
// Adds lifecycle fields used to exclude closed accounts from live balances.
require_once __DIR__ . '/../Database.php';

$db = Database::getConnection();

$result = $db->query("SHOW COLUMNS FROM `accounts` LIKE 'closed'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `accounts` ADD COLUMN `closed` TINYINT NOT NULL DEFAULT 0");
}

$result = $db->query("SHOW COLUMNS FROM `accounts` LIKE 'closed_at'");
if ($result->rowCount() === 0) {
    $db->exec("ALTER TABLE `accounts` ADD COLUMN `closed_at` DATE DEFAULT NULL");
}

echo "Closed-account schema is ready.\n";
?>
