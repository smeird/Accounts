<?php
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
$scheme = $brand['color_scheme'];
$fonts = Setting::getFonts();
$fontHeading = $fonts['heading'];
$fontBody = $fonts['body'];
$fontAccent = $fonts['accent'];
$fontAccentWeight = $fonts['accent_weight'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_SESSION['pending_user_id'])) {
        $username = $_SESSION['pending_username'] ?? '';
        $token = $_POST['token'] ?? '';
        $stmt = $db->prepare('SELECT secret FROM totp_secrets WHERE username = :username');
        $stmt->execute(['username' => $username]);
        $secret = $stmt->fetchColumn();
        if ($secret && Totp::verifyCode($secret, $token)) {
            $_SESSION['user_id'] = (int)$_SESSION['pending_user_id'];
            $_SESSION['username'] = $username;
            unset($_SESSION['pending_user_id'], $_SESSION['pending_username']);
            Log::write("User '$username' passed 2FA");
            header('Location: frontend/index.html');
            exit;
        }
        $error = 'Invalid code';
        Log::write("2FA failure for '$username'", 'ERROR');
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $reason = '';
        $userId = User::verify($username, $password, $reason);
        if ($userId !== null) {
            $stmt = $db->prepare('SELECT 1 FROM totp_secrets WHERE username = :username');
            $stmt->execute(['username' => $username]);
            if ($stmt->fetchColumn()) {
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
    <title><?= htmlspecialchars($siteName) ?> Login</title>
    <script>window.tailwind = window.tailwind || {}; window.tailwind.config = {};</script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/png" sizes="any" href="/favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=<?= urlencode($fontHeading) ?>:wght@700&family=<?= urlencode($fontBody) ?>:wght@400&family=<?= urlencode($fontAccent) ?>:wght@<?= urlencode($fontAccentWeight) ?>&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body { font-family: '<?= htmlspecialchars($fontBody, ENT_QUOTES) ?>', sans-serif; font-weight: 400; }
        h1, h2, h3, h4, h5, h6 { font-family: '<?= htmlspecialchars($fontHeading, ENT_QUOTES) ?>', sans-serif; font-weight: 700; }
        button { font-family: '<?= htmlspecialchars($fontAccent, ENT_QUOTES) ?>', sans-serif; font-weight: <?= htmlspecialchars($fontAccentWeight, ENT_QUOTES) ?>; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center bg-gray-50">
    <div class="w-full max-w-sm bg-white p-6 rounded shadow border border-gray-400">
        <img src="favicon.png" alt="<?= htmlspecialchars($siteName) ?> logo" class="h-24 w-24 mb-4 block mx-auto rounded shadow" />
        <div class="uppercase text-<?= $scheme ?>-900 text-[0.6rem] mb-1 text-center">AUTHENTICATION / <?= $needsToken ? 'TWO-FACTOR' : 'LOGIN' ?></div>
        <h1 class="text-2xl font-semibold mb-4 text-center text-<?= $scheme ?>-700"><?= $needsToken ? 'Enter Code' : 'Login' ?></h1>
        <p class="mb-4 text-center">
            <?= $needsToken ? 'Enter the 6-digit code from your authenticator.' : 'Use your account credentials to sign in and access the ' . htmlspecialchars($siteName) . '. Enter your username and password in the boxes below and press the login button to continue.' ?>
        </p>
        <?php if ($error): ?>
            <p class="mb-4 text-red-500 text-center"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <?php if ($needsToken): ?>
            <form method="post" class="space-y-4" id="token-form">
                <label class="block">Code:
                    <input type="text" name="token" autocomplete="one-time-code" class="mt-1 w-full border p-2 rounded" data-help="Enter your 6-digit code">
                </label>
                <button type="submit" aria-label="Verify code" class="w-full bg-<?= $scheme ?>-600 hover:bg-<?= $scheme ?>-700 text-white py-2 rounded transition duration-100 transform hover:-translate-y-0.5 hover:shadow-lg">Verify</button>
            </form>
        <?php else: ?>
            <form method="post" class="space-y-4" id="login-form" autocomplete="on">
                <label class="block">Username:
                    <input type="text" name="username" autocomplete="username" class="mt-1 w-full border p-2 rounded" data-help="Enter your username">
                </label>
                <label class="block">Password:
                    <input type="password" name="password" autocomplete="current-password" class="mt-1 w-full border p-2 rounded" data-help="Enter your password">
                </label>
                <button type="submit" aria-label="Log in" class="w-full bg-<?= $scheme ?>-600 hover:bg-<?= $scheme ?>-700 text-white py-2 rounded transition duration-100 transform hover:-translate-y-0.5 hover:shadow-lg">Login</button>
            </form>
        <?php endif; ?>
    </div>
    <script src="frontend/js/input_help.js"></script>
    <script src="frontend/js/page_help.js"></script>
    <script src="frontend/js/overlay.js"></script>
    <script src="frontend/js/aria_tooltips.js"></script>
    <script src="frontend/js/tooltips.js"></script>
</body>
</html>
