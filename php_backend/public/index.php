<?php
require_once __DIR__ . '/../models/Log.php';
require_once __DIR__ . '/../models/Account.php';

$accountId = Account::create('Checking');

echo "Created account with ID: $accountId\n";
?>
