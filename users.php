<?php
// Simple user management page to add users, change passwords, and manage 2FA.
require_once __DIR__ . '/php_backend/auth.php';
require_once __DIR__ . '/php_backend/models/User.php';
require_once __DIR__ . '/php_backend/Totp.php';
require_once __DIR__ . '/php_backend/Database.php';
require_once __DIR__ . '/php_backend/models/Log.php';
require_once __DIR__ . '/php_backend/models/Setting.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$timeoutSetting = (int) (Setting::get('session_timeout_minutes') ?? 0);
if ($timeoutSetting > 0) {
    $lastActivity = $_SESSION['last_activity'] ?? 0;
    if ($lastActivity && (time() - $lastActivity) > $timeoutSetting * 60) {
        Log::write('Session expired for user ' . $_SESSION['user_id'], 'WARN');
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }
        header('Location: logout.php?timeout=1');
        exit;
    }
}
$_SESSION['last_activity'] = time();

$brand = Setting::getBrand();

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
                Log::write('User add error: ' . $e->getMessage(), 'ERROR');
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
    <title>User Management</title>
    <script>
        window.tailwind = window.tailwind || {};
        window.tailwind.config = {};
    </script>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="frontend/operational_ui.css">
    <link rel="icon" type="image/png" sizes="any" href="/favicon.png">

      <!-- Font Awesome icons loaded via frontend/js/menu.js -->
      <style>
          a { transition: color 0.2s ease; }
          a:hover { color: #4f46e5; }
          button { transition: transform 0.1s ease, box-shadow 0.1s ease; }
          button:hover { transform: translateY(-2px); box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
      </style>
</head>
<body class="ops-body" data-api-base="php_backend/public">
    <div class="flex min-h-screen">
        <nav id="menu" class="hidden md:flex md:flex-col w-64 flex-shrink-0 bg-transparent p-6 overflow-y-auto"></nav>
        <main class="ops-main flex-1 min-w-0 overflow-x-auto">
    <div class="max-w-2xl mx-auto bg-white p-6 rounded shadow border border-gray-400">
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
        <div class="bg-white p-4 rounded shadow border border-gray-400 space-y-4 mb-6">
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
        </main>
    </div>
    <script src="frontend/js/menu.js"></script>
    <script src="frontend/js/input_help.js"></script>
    <script src="frontend/js/page_help.js"></script>
    <script src="frontend/js/overlay.js"></script>
    <script src="frontend/js/aria_tooltips.js"></script>
    <script src="frontend/js/tooltips.js"></script>
    <script src="frontend/js/fonts.js"></script>
    <script>
      applyFonts({
        heading_font: <?= json_encode($brand['heading_font']) ?>,
        body_font: <?= json_encode($brand['body_font']) ?>,
        table_font: <?= json_encode($brand['table_font']) ?>,
        chart_font: <?= json_encode($brand['chart_font']) ?>,
        accent_font_weight: <?= json_encode($brand['accent_font_weight']) ?>
      });
    </script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

    <script src="frontend/js/2fa.js"></script>
</body>
</html>
