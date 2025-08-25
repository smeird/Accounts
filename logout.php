<?php
// Log out the current user and show confirmation page.
ini_set('session.cookie_secure', '1');
session_start();
require_once __DIR__ . '/php_backend/nocache.php';
require_once __DIR__ . '/php_backend/models/Log.php';
require_once __DIR__ . '/php_backend/models/Setting.php';

if (isset($_SESSION['user_id'])) {
    $reason = isset($_GET['timeout']) ? ' (timeout)' : '';
    Log::write('User ' . $_SESSION['user_id'] . ' logged out' . $reason);
}

session_destroy();

if (isset($_GET['timeout'])) {
    header('Location: index.php');
    exit;
}
$brand = Setting::getBrand();
$siteName = $brand['site_name'];
$colorScheme = $brand['color_scheme'];
$colorMap = [
    'indigo' => ['600' => '#4f46e5', '700' => '#4338ca'],
    'blue'   => ['600' => '#2563eb', '700' => '#1d4ed8'],
    'green'  => ['600' => '#059669', '700' => '#047857'],
    'red'    => ['600' => '#dc2626', '700' => '#b91c1c'],
    'purple' => ['600' => '#9333ea', '700' => '#7e22ce'],
    'teal'   => ['600' => '#0d9488', '700' => '#0f766e'],
    'orange' => ['600' => '#ea580c', '700' => '#c2410c'],
];
$text700 = "text-{$colorScheme}-700";
$bg600 = "bg-{$colorScheme}-600";
$hoverHex = $colorMap[$colorScheme]['600'] ?? '#4f46e5';
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
        a:hover { color: <?= $hoverHex ?>; }
        button { transition: transform 0.1s ease, box-shadow 0.1s ease; }
        button:hover { transform: translateY(-2px); box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center bg-gray-50">
    <div class="w-full max-w-sm bg-white p-6 rounded shadow text-center">
        <img src="favicon.svg" alt="<?= htmlspecialchars($siteName) ?> logo" class="h-24 w-24 mb-4 mx-auto" />
        <h1 class="text-2xl font-semibold mb-4 <?= $text700 ?>">Logged Out</h1>
        <p class="mb-4">You have been safely logged out of the <?= htmlspecialchars($siteName) ?>.</p>
        <a href="index.php" class="<?= $bg600 ?> text-white px-4 py-2 rounded">Return to Login</a>
    </div>
    <script src="frontend/js/keyboard_hints.js"></script>
    <script src="frontend/js/page_help.js"></script>
</body>
</html>
