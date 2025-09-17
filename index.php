<?php
require_once __DIR__ . '/php_backend/auth.php';
require_once __DIR__ . '/php_backend/models/User.php';
require_once __DIR__ . '/php_backend/models/Log.php';
require_once __DIR__ . '/php_backend/Totp.php';
require_once __DIR__ . '/php_backend/Database.php';
require_once __DIR__ . '/php_backend/models/Setting.php';

$db = Database::getConnection();
$brand = Setting::getBrand();
$siteName = $brand['site_name'];
$scheme = $brand['color_scheme'];
$headingFont = $brand['heading_font'];
$bodyFont = $brand['body_font'];
$tableFont = $brand['table_font'];
$chartFont = $brand['chart_font'];
$accentWeight = $brand['accent_font_weight'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_SESSION['pending_user_id'])) {
        $username = $_SESSION['pending_username'] ?? '';
        $token = $_POST['token'] ?? '';
        $stmt = $db->prepare('SELECT secret FROM totp_secrets WHERE username = :username');
        $stmt->execute(['username' => $username]);
        $secret = $stmt->fetchColumn();
        if ($secret && Totp::verifyCode($secret, $token)) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$_SESSION['pending_user_id'];
            $_SESSION['username'] = $username;
            $_SESSION['last_activity'] = time();
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
                session_regenerate_id(true);
                $_SESSION['user_id'] = $userId;
                $_SESSION['username'] = $username;
                $_SESSION['last_activity'] = time();
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="relative min-h-screen flex items-center justify-center overflow-hidden bg-gradient-to-br from-slate-950 via-slate-900 to-slate-950 text-slate-100">
    <div class="pointer-events-none absolute inset-0 overflow-hidden">
        <div class="absolute -top-32 -left-24 h-80 w-80 rounded-full bg-gradient-to-br from-emerald-400/40 via-teal-400/30 to-transparent blur-3xl"></div>
        <div class="absolute bottom-[-5rem] left-1/2 h-72 w-[32rem] -translate-x-1/2 rounded-full bg-gradient-to-r from-white/20 via-white/10 to-transparent blur-[120px]"></div>
        <div class="absolute top-1/2 right-[-6rem] h-96 w-96 -translate-y-1/2 rounded-full bg-gradient-to-tr from-sky-400/30 via-indigo-400/20 to-transparent blur-3xl"></div>
    </div>
    <div class="relative z-10 w-full max-w-md px-6">
        <div class="relative overflow-hidden rounded-3xl border border-white/20 bg-white/10 p-8 shadow-[0_35px_60px_-15px_rgba(15,23,42,0.9)] backdrop-blur-2xl">
            <div class="absolute inset-0 -z-10 rounded-3xl bg-gradient-to-br from-white/20 via-transparent to-white/5"></div>
            <div class="absolute inset-[1px] -z-10 rounded-[1.45rem] border border-white/10"></div>
            <div class="mb-6 flex items-center justify-between text-xs uppercase tracking-[0.3em] text-white/70">
                <span>Authentication</span>
                <span><?= $needsToken ? 'Two-Factor' : 'Login' ?></span>
            </div>
            <div class="mx-auto mb-6 flex h-20 w-20 items-center justify-center rounded-2xl border border-white/30 bg-white/10 shadow-inner">
                <img src="favicon.png" alt="<?= htmlspecialchars($siteName) ?> logo" class="h-12 w-12" />
            </div>
            <h1 class="mb-3 text-center text-3xl font-semibold text-white"><?= $needsToken ? 'Enter Code' : 'Welcome Back' ?></h1>
            <p class="mb-6 text-center text-sm text-white/80">
                <?= $needsToken ? 'Enter the 6-digit code from your authenticator.' : 'Use your account credentials to sign in and access the ' . htmlspecialchars($siteName) . '. Enter your username and password below to continue.' ?>
            </p>
            <?php if ($error): ?>
                <p class="mb-4 text-center text-sm text-red-300/90"><?= htmlspecialchars($error) ?></p>
            <?php endif; ?>
            <?php if ($needsToken): ?>
                <form method="post" class="space-y-4" id="token-form">
                    <label class="block text-sm font-medium text-white/80">Code
                        <input type="text" name="token" autocomplete="one-time-code" class="mt-2 w-full rounded-xl border border-white/30 bg-white/10 px-4 py-3 text-base text-white placeholder-white/50 focus:border-<?= $scheme ?>-300 focus:outline-none focus:ring-2 focus:ring-<?= $scheme ?>-300" data-help="Enter your 6-digit code">
                    </label>
                    <button type="submit" aria-label="Verify code" class="w-full rounded-xl bg-<?= $scheme ?>-500/90 py-3 text-base font-semibold text-white shadow-[0_15px_35px_rgba(15,23,42,0.45)] transition duration-150 hover:-translate-y-0.5 hover:bg-<?= $scheme ?>-400/90">Verify</button>
                </form>
            <?php else: ?>
                <form method="post" class="space-y-4" id="login-form" autocomplete="on">
                    <label class="block text-sm font-medium text-white/80">Username
                        <input type="text" name="username" autocomplete="username" class="mt-2 w-full rounded-xl border border-white/30 bg-white/10 px-4 py-3 text-base text-white placeholder-white/50 focus:border-<?= $scheme ?>-300 focus:outline-none focus:ring-2 focus:ring-<?= $scheme ?>-300" data-help="Enter your username">
                    </label>
                    <label class="block text-sm font-medium text-white/80">Password
                        <input type="password" name="password" autocomplete="current-password" class="mt-2 w-full rounded-xl border border-white/30 bg-white/10 px-4 py-3 text-base text-white placeholder-white/50 focus:border-<?= $scheme ?>-300 focus:outline-none focus:ring-2 focus:ring-<?= $scheme ?>-300" data-help="Enter your password">
                    </label>
                    <button type="submit" aria-label="Log in" class="w-full rounded-xl bg-<?= $scheme ?>-500/90 py-3 text-base font-semibold text-white shadow-[0_15px_35px_rgba(15,23,42,0.45)] transition duration-150 hover:-translate-y-0.5 hover:bg-<?= $scheme ?>-400/90">Login</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <script src="frontend/js/input_help.js"></script>
    <script src="frontend/js/page_help.js"></script>
    <script src="frontend/js/overlay.js"></script>
    <script src="frontend/js/aria_tooltips.js"></script>
    <script src="frontend/js/tooltips.js"></script>
    <script src="frontend/js/fonts.js"></script>
    <script>
      applyFonts({
        heading_font: <?= json_encode($headingFont) ?>,
        body_font: <?= json_encode($bodyFont) ?>,
        table_font: <?= json_encode($tableFont) ?>,
        chart_font: <?= json_encode($chartFont) ?>,
        accent_font_weight: <?= json_encode($accentWeight) ?>
      });
    </script>
</body>
</html>
