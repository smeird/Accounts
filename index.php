<?php
// Simple login page with optional 2FA verification.
ini_set('session.cookie_secure', '1');
session_start();
require_once __DIR__ . '/php_backend/nocache.php';
require_once __DIR__ . '/php_backend/models/User.php';
require_once __DIR__ . '/php_backend/models/Log.php';
require_once __DIR__ . '/php_backend/Totp.php';

$totpFile = __DIR__ . '/php_backend/totp_secrets.json';
$totpUsers = file_exists($totpFile) ? json_decode(file_get_contents($totpFile), true) : [];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_SESSION['pending_user_id'])) {
        // Verify TOTP token
        $token = $_POST['token'] ?? '';
        $username = $_SESSION['pending_username'] ?? '';
        $secret = $totpUsers[$username] ?? null;
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
            if (isset($totpUsers[$username])) {
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
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <meta name="application-name" content="Finance Manager">
    <title>Finance Manager Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
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
    <div class="w-full max-w-sm bg-white p-6 rounded shadow">
        <img src="favicon.svg" alt="Finance Manager Logo" class="w-24 mx-auto mb-4">
        <div class="uppercase text-indigo-900 text-[0.6rem] mb-1 text-center">AUTHENTICATION / <?= $needsToken ? 'TWO-FACTOR' : 'LOGIN' ?></div>
        <h1 class="text-2xl font-semibold mb-4 text-center text-indigo-700"><?= $needsToken ? 'Enter Code' : 'Login' ?></h1>
        <p class="mb-4 text-center">
            <?= $needsToken ? 'Enter the 6-digit code from your authenticator.' : 'Use your account credentials to sign in and access the finance manager. Enter your username and password in the boxes below and press the login button to continue.' ?>
        </p>
        <?php if ($error): ?>
            <p class="mb-4 text-red-500 text-center"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <?php if ($needsToken): ?>
            <form method="post" id="token-form" class="space-y-4">
                <label class="block">Code:
                    <input type="text" name="token" autocomplete="one-time-code" class="mt-1 w-full border p-2 rounded" data-help="Enter your 6-digit code">
                </label>
                <button type="submit" class="w-full bg-indigo-600 text-white py-2 rounded">Verify</button>
            </form>
        <?php else: ?>
            <form method="post" id="login-form" name="login-form" autocomplete="on" class="space-y-4">
                <label class="block">Username:
                    <input type="text" name="username" autocomplete="username" class="mt-1 w-full border p-2 rounded" data-help="Enter your username">
                </label>
                <label class="block">Password:
                    <input type="password" name="password" autocomplete="current-password" class="mt-1 w-full border p-2 rounded" data-help="Enter your password">
                </label>
                <button type="submit" class="w-full bg-indigo-600 text-white py-2 rounded">Login</button>
            </form>
        <?php endif; ?>
    </div>
    <script src="frontend/js/input_help.js"></script>
    <script src="frontend/js/keyboard_hints.js"></script>
    <script src="frontend/js/page_help.js"></script>
</body>
</html>
