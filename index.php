<?php
// Simple login page with optional 2FA verification.
ini_set('session.cookie_secure', '1');
session_start();
require_once __DIR__ . '/php_backend/nocache.php';
require_once __DIR__ . '/php_backend/models/User.php';
require_once __DIR__ . '/php_backend/models/Log.php';
require_once __DIR__ . '/php_backend/Totp.php';
require_once __DIR__ . '/php_backend/Database.php';
require_once __DIR__ . '/php_backend/models/Setting.php';

$db = Database::getConnection();
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
$text600 = "text-{$colorScheme}-600";
$text700 = "text-{$colorScheme}-700";
$text900 = "text-{$colorScheme}-900";
$bg600 = "bg-{$colorScheme}-600";
$bgHover = "hover:bg-{$colorScheme}-700";
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_SESSION['pending_user_id'])) {
        // Verify TOTP token
        $token = $_POST['token'] ?? '';
        $username = $_SESSION['pending_username'] ?? '';
        $secret = null;
        if ($username !== '') {
            $stmt = $db->prepare('SELECT secret FROM totp_secrets WHERE username = :username');
            $stmt->execute(['username' => $username]);
            $secret = $stmt->fetchColumn() ?: null;
        }
        if ($secret && Totp::verifyCode($secret, $token)) {
            $_SESSION['user_id'] = (int)$_SESSION['pending_user_id'];
            $_SESSION['username'] = $username;
            unset($_SESSION['pending_user_id'], $_SESSION['pending_username']);
            Log::write("User '$username' passed 2FA");
            header('Location: frontend/index.html');
            exit;
        } else {
            $error = 'Invalid code';
            Log::write("2FA failure for '$username'", 'ERROR');
        }
    } else {
        // Verify username and password
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $reason = '';
        $userId = User::verify($username, $password, $reason);
        if ($userId !== null) {
            $stmt = $db->prepare('SELECT 1 FROM totp_secrets WHERE username = :username');
            $stmt->execute(['username' => $username]);
            if ($stmt->fetchColumn()) {
                // Require 2FA token
                $_SESSION['pending_user_id'] = $userId;
                $_SESSION['pending_username'] = $username;
            } else {
                $_SESSION['user_id'] = $userId;
                $_SESSION['username'] = $username;
                Log::write("User '$username' logged in");
                header('Location: frontend/index.html');
                exit;
            }
        } else {
            $error = 'Invalid credentials';
            Log::write("Login failed for '$username': $reason", 'ERROR');
        }
    }
}

$needsToken = isset($_SESSION['pending_user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="application-name" content="<?= htmlspecialchars($siteName) ?>">
    <?php $origin = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? ''); ?>
    <meta property="og:title" content="<?= htmlspecialchars($siteName) ?>">
    <meta property="og:description" content="Finance management system for tracking budgets and expenses.">
    <meta property="og:image" content="<?= htmlspecialchars($origin) ?>/favicon.png">
    <meta property="og:url" content="<?= htmlspecialchars($origin . $_SERVER['REQUEST_URI']) ?>">
    <title><?= htmlspecialchars($siteName) ?> Login</title>
    <script>
        window.tailwind = window.tailwind || {};
        window.tailwind.config = { darkMode: "class" };
    </script>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/png" sizes="any" href="/favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@700&family=Inter:wght@400&family=Source+Sans+Pro:wght@300&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="min-h-screen flex items-center justify-center bg-gray-50 font-['Inter'] dark:bg-gray-900 dark:text-gray-100">
    <div class="w-full max-w-sm bg-white p-6 rounded shadow border border-gray-400 dark:bg-gray-800 dark:border-gray-700">
        <img src="favicon.png" alt="<?= htmlspecialchars($siteName) ?> logo" class="h-24 w-24 mb-4 block mx-auto rounded shadow" />
        <div class="uppercase <?= $text900 ?> text-[0.6rem] mb-1 text-center">AUTHENTICATION / <?= $needsToken ? 'TWO-FACTOR' : 'LOGIN' ?></div>
        <h1 class="font-['Roboto'] text-2xl font-semibold mb-4 text-center <?= $text700 ?>"><?= $needsToken ? 'Enter Code' : 'Login' ?></h1>
        <p class="mb-4 text-center">
            <?= $needsToken ? 'Enter the 6-digit code from your authenticator.' : 'Use your account credentials to sign in and access the ' . htmlspecialchars($siteName) . '. Enter your username and password in the boxes below and press the login button to continue.' ?>
        </p>
        <?php if ($error): ?>
            <p class="mb-4 text-red-500 text-center"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <?php if ($needsToken): ?>
            <form method="post" id="token-form" class="space-y-4">
                <label class="block">Code:
                    <input type="text" name="token" autocomplete="one-time-code" class="mt-1 w-full border p-2 rounded" data-help="Enter your 6-digit code">
                </label>
                <button type="submit" class="w-full <?= $bg600 ?> <?= $bgHover ?> text-white py-2 rounded font-['Source_Sans_Pro'] font-light transition duration-100 transform hover:-translate-y-0.5 hover:shadow-lg">Verify</button>
            </form>
        <?php else: ?>
            <form method="post" id="login-form" name="login-form" autocomplete="on" class="space-y-4">
                <label class="block">Username:
                    <input type="text" name="username" autocomplete="username" class="mt-1 w-full border p-2 rounded" data-help="Enter your username">
                </label>
                <label class="block">Password:
                    <input type="password" name="password" autocomplete="current-password" class="mt-1 w-full border p-2 rounded" data-help="Enter your password">
                </label>
                <button type="submit" class="w-full <?= $bg600 ?> <?= $bgHover ?> text-white py-2 rounded font-['Source_Sans_Pro'] font-light transition duration-100 transform hover:-translate-y-0.5 hover:shadow-lg">Login</button>
            </form>
        <?php endif; ?>
    </div>
    <script src="frontend/js/theme_toggle.js"></script>
    <script src="frontend/js/input_help.js"></script>
    <script src="frontend/js/keyboard_hints.js"></script>
    <script src="frontend/js/page_help.js"></script>
</body>
</html>
