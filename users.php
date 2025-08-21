<?php
// Simple user management page to add users, change passwords, and manage 2FA.
ini_set('session.cookie_secure', '1');
session_start();
require_once __DIR__ . '/php_backend/models/User.php';
require_once __DIR__ . '/php_backend/nocache.php';
require_once __DIR__ . '/php_backend/Totp.php';
require_once __DIR__ . '/php_backend/Database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$username = $_SESSION['username'] ?? '';
$db = Database::getConnection();
$has2fa = false;
if ($username !== '') {
    $stmt = $db->prepare('SELECT 1 FROM totp_secrets WHERE username = :username');
    $stmt->execute(['username' => $username]);
    $has2fa = (bool)$stmt->fetchColumn();
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $usernameNew = $_POST['username'] ?? '';
        $passwordNew = $_POST['password'] ?? '';
        if ($usernameNew && $passwordNew) {
            try {
                User::create($usernameNew, $passwordNew);
                $message = 'User added.';
            } catch (Exception $e) {
                $message = 'Error adding user.';
            }
        } else {
            $message = 'Username and password required.';
        }
    } elseif ($action === 'update') {
        $password = $_POST['password'] ?? '';
        if ($password) {
            User::updatePassword((int)$_SESSION['user_id'], $password);
            $message = 'Password updated.';
        } else {
            $message = 'Password required.';
        }
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
    <title>User Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/svg+xml" sizes="any" href="/favicon.svg">

    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700&family=Inter:wght@400&family=Source+Sans+Pro:wght@300&display=swap" rel="stylesheet">

    <!-- Font Awesome icons loaded via frontend/js/menu.js -->
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
<body class="min-h-screen bg-gray-50 p-6" data-api-base="php_backend/public">
    <div class="max-w-2xl mx-auto bg-white p-6 rounded shadow">
        <i class="fas fa-piggy-bank text-indigo-600 text-6xl mb-4 block mx-auto"></i>
        <div class="uppercase text-indigo-900 text-[0.6rem] mb-1">ADMIN TOOLS / MANAGE USERS</div>
        <h1 class="text-2xl font-semibold mb-4 text-indigo-700">User Management</h1>
        <p class="mb-4">Add new users, update your password, or manage two-factor authentication from this page.</p>
        <p class="mb-4"><a href="logout.php" class="text-indigo-600 hover:underline">Logout</a> | <a href="frontend/index.html" class="text-indigo-600 hover:underline">Home</a></p>
        <?php if ($message): ?>
            <p class="mb-4 text-green-600"><?= htmlspecialchars($message) ?></p>
        <?php endif; ?>

        <h2 class="text-xl font-semibold mt-6 mb-2">Add User</h2>
        <form method="post" class="space-y-4 mb-6">
            <input type="hidden" name="action" value="add">
            <label class="block">Username: <input type="text" name="username" class="border p-2 rounded w-full" data-help="Choose a username"></label>
            <label class="block">Password: <input type="password" name="password" class="border p-2 rounded w-full" data-help="Set a password"></label>
            <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded">Add User</button>
        </form>

        <h2 class="text-xl font-semibold mt-6 mb-2">Update Password</h2>
        <form method="post" class="space-y-4 mb-6">
            <input type="hidden" name="action" value="update">
            <label class="block">New Password: <input type="password" name="password" class="border p-2 rounded w-full" data-help="Enter your new password"></label>
            <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded">Update Password</button>
        </form>

        <h2 class="text-xl font-semibold mt-6 mb-2">Two-Factor Authentication</h2>
        <p class="mb-4"><?= $has2fa ? '2FA is enabled for your account.' : '2FA is not enabled. Generate a secret to enable it.' ?></p>
        <div class="bg-white p-4 rounded shadow space-y-4 mb-6">
            <form id="generate-form" class="space-y-4">
                <input type="hidden" id="gen-username" value="<?= htmlspecialchars($username) ?>">
                <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded"><i class="fas fa-qrcode inline w-4 h-4 mr-2"></i>Generate QR</button>
            </form>

            <div id="qr" class="mt-4 mx-auto"></div>

            <form id="verify-form" class="space-y-4">
                <input type="hidden" id="ver-username" value="<?= htmlspecialchars($username) ?>">
                <input id="token" type="text" placeholder="TOTP Code" class="border p-2 rounded w-full" data-help="Enter the 6-digit code from your authenticator">
                <div class="flex space-x-2">
                    <button type="submit" class="flex-1 bg-indigo-600 text-white px-4 py-2 rounded"><i class="fas fa-check inline w-4 h-4 mr-2"></i>Verify</button>
                    <button id="disable-2fa" type="button" class="flex-1 bg-gray-500 text-white px-4 py-2 rounded"><i class="fas fa-ban inline w-4 h-4 mr-2"></i>Disable</button>
                </div>
            </form>
        </div>
    </div>
    <script src="frontend/js/input_help.js"></script>
    <script src="frontend/js/keyboard_hints.js"></script>
    <script src="frontend/js/page_help.js"></script>
    <script src="frontend/js/overlay.js"></script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

    <script src="frontend/js/2fa.js"></script>
</body>
</html>
