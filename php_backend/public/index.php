<?php
// Simple script to insert a sample account for testing.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/Log.php';
require_once __DIR__ . '/../models/Account.php';

$accountId = Account::create('Checking');

echo "Created account with ID: $accountId\n";
?>
