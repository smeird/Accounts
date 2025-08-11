<?php
// Log out the current user and redirect to login page.
session_start();
require_once __DIR__ . '/php_backend/nocache.php';
session_destroy();
header('Location: index.php');
exit;
?>
