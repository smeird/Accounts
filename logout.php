<?php
// Log out the current user and redirect to login page.
ini_set('session.cookie_secure', '1');
session_start();
require_once __DIR__ . '/php_backend/nocache.php';
require_once __DIR__ . '/php_backend/models/Log.php';

if (isset($_SESSION['user_id'])) {
    Log::write('User ' . $_SESSION['user_id'] . ' logged out');
}

session_destroy();
header('Location: index.php');
exit;
?>
