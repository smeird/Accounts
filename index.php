<?php
// Simple login page for user authentication.
ini_set('session.cookie_secure', '1');
session_start();
require_once __DIR__ . '/php_backend/nocache.php';
require_once __DIR__ . '/php_backend/models/User.php';
require_once __DIR__ . '/php_backend/models/Log.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $reason = '';
    $userId = User::verify($username, $password, $reason);

    if ($userId !== null) {
        $_SESSION['user_id'] = $userId;
        header('Location: frontend/index.html');
        exit;
    } else {
        $error = 'Invalid credentials';

        Log::write("Login failed for '$username': $reason", 'ERROR');

    }
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
    <title>Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/svg+xml" href="frontend/wallet.svg">
    <style>
        a { transition: color 0.2s ease; }
        a:hover { color: #4f46e5; }
        button { transition: transform 0.1s ease, box-shadow 0.1s ease; }
        button:hover { transform: translateY(-2px); box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center bg-gray-50 font-sans">
    <div class="w-full max-w-sm bg-white p-6 rounded shadow">
        <img src="frontend/wallet.svg" alt="Finance Manager Logo" class="w-24 mx-auto mb-4">
        <div class="uppercase text-indigo-900 text-[0.6rem] mb-1 text-center">AUTHENTICATION / LOGIN</div>
        <h1 class="text-2xl font-semibold mb-4 text-center">Login</h1>
        <p class="mb-4 text-center">Use your account credentials to sign in and access the finance manager. Enter your username and password in the boxes below and press the login button to continue.</p>
        <?php if ($error): ?>
            <p class="mb-4 text-red-500 text-center"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <form method="post" class="space-y-4">
            <label class="block">Username:
                <input type="text" name="username" class="mt-1 w-full border p-2 rounded" data-help="Enter your username">
            </label>
            <label class="block">Password:
                <input type="password" name="password" class="mt-1 w-full border p-2 rounded" data-help="Enter your password">
            </label>
            <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded">Login</button>
        </form>
    </div>
    <script src="frontend/js/input_help.js"></script>
    <script src="frontend/js/page_help.js"></script>
</body>
</html>
