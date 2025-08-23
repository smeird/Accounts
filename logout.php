<?php
// Log out the current user and show confirmation page.
ini_set('session.cookie_secure', '1');
session_start();
require_once __DIR__ . '/php_backend/nocache.php';
require_once __DIR__ . '/php_backend/models/Log.php';

if (isset($_SESSION['user_id'])) {
    $reason = isset($_GET['timeout']) ? ' (timeout)' : '';
    Log::write('User ' . $_SESSION['user_id'] . ' logged out' . $reason);
}

session_destroy();

if (isset($_GET['timeout'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Logged Out</title>
    <link rel="icon" type="image/svg+xml" sizes="any" href="/favicon.svg">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@700&family=Inter:wght@400&family=Source+Sans+Pro:wght@300&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        h1, h2, h3, h4, h5, h6 { font-family: 'Roboto', sans-serif; font-weight: 700; }
        button, .accent { font-family: 'Source Sans Pro', sans-serif; font-weight: 300; }
        a { transition: color 0.2s ease; }
        a:hover { color: #4f46e5; }
        button { transition: transform 0.1s ease, box-shadow 0.1s ease; }
        button:hover { transform: translateY(-2px); box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center bg-gray-50">
    <div class="w-full max-w-sm bg-white p-6 rounded shadow text-center">
        <img src="favicon.svg" alt="Finance Manager logo" class="h-24 w-24 mb-4 mx-auto" />
        <h1 class="text-2xl font-semibold mb-4 text-indigo-700">Logged Out</h1>
        <p class="mb-4">You have been safely logged out of the finance manager.</p>
        <a href="index.php" class="bg-indigo-600 text-white px-4 py-2 rounded">Return to Login</a>
    </div>
    <script src="frontend/js/keyboard_hints.js"></script>
    <script src="frontend/js/page_help.js"></script>
</body>
</html>
