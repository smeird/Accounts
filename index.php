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
<body class="min-h-screen bg-slate-100 text-slate-900">
    <div class="relative isolate min-h-screen overflow-hidden">
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(30,64,175,0.15),transparent_45%),radial-gradient(circle_at_bottom_left,rgba(15,118,110,0.15),transparent_40%)]"></div>
        <main class="relative z-10 mx-auto flex min-h-screen w-full max-w-6xl items-center px-6 py-12 lg:px-10">
            <div class="grid w-full gap-8 rounded-3xl border border-slate-200 bg-white shadow-2xl shadow-slate-300/60 lg:grid-cols-2">
                <section class="rounded-t-3xl bg-slate-900 p-8 text-slate-100 lg:rounded-l-3xl lg:rounded-tr-none lg:p-10">
                    <div class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-slate-100">
                        <i class="fa-solid fa-shield-halved text-emerald-300"></i>
                        Secure Access
                    </div>
                    <h1 class="mt-6 text-3xl font-semibold leading-tight"><?= htmlspecialchars($siteName) ?></h1>
                    <p class="mt-4 text-sm leading-6 text-slate-300">Centralise your financial management with fast reporting, AI-assisted organisation, and secure account controls.</p>
                    <dl class="mt-8 space-y-4 text-sm">
                        <div class="flex items-start gap-3">
                            <dt class="mt-0.5 text-emerald-300"><i class="fa-solid fa-chart-line"></i></dt>
                            <dd class="text-slate-200">Track account activity, budgets, and project outcomes in one place.</dd>
                        </div>
                        <div class="flex items-start gap-3">
                            <dt class="mt-0.5 text-emerald-300"><i class="fa-solid fa-wand-magic-sparkles"></i></dt>
                            <dd class="text-slate-200">Use AI-powered tagging and budgeting tools to reduce admin time.</dd>
                        </div>
                        <div class="flex items-start gap-3">
                            <dt class="mt-0.5 text-emerald-300"><i class="fa-solid fa-lock"></i></dt>
                            <dd class="text-slate-200">Protect data with role-based access and optional two-factor authentication.</dd>
                        </div>
                    </dl>
                </section>
                <section class="p-8 lg:p-10">
                    <div class="mb-6 flex items-center justify-between text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">
                        <span>Authentication</span>
                        <span><?= $needsToken ? 'Two-Factor' : 'Login' ?></span>
                    </div>
                    <div class="mb-6 flex items-center gap-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-white shadow-sm">
                            <img src="favicon.png" alt="<?= htmlspecialchars($siteName) ?> logo" class="h-8 w-8" />
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-slate-900"><?= $needsToken ? 'Two-factor verification' : 'Welcome back' ?></p>
                            <p class="text-xs text-slate-600">Sign in to continue to your dashboard.</p>
                        </div>
                    </div>
                    <p class="mb-6 text-sm leading-6 text-slate-700">
                        <?= $needsToken ? 'Enter the 6-digit code from your authenticator app to complete sign in.' : 'Use your account credentials to access the ' . htmlspecialchars($siteName) . ' workspace.' ?>
                    </p>
                    <?php if ($error): ?>
                        <p class="mb-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm font-medium text-red-700"><?= htmlspecialchars($error) ?></p>
                    <?php endif; ?>
                    <?php if ($needsToken): ?>
                        <form method="post" class="space-y-4" id="token-form">
                            <label class="block text-sm font-semibold text-slate-700">Code
                                <input type="text" name="token" autocomplete="one-time-code" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-base text-slate-900 placeholder-slate-400 shadow-sm focus:border-<?= $scheme ?>-500 focus:outline-none focus:ring-2 focus:ring-<?= $scheme ?>-200" data-help="Enter your 6-digit code">
                            </label>
                            <button type="submit" aria-label="Verify code" class="w-full rounded-xl bg-<?= $scheme ?>-600 py-3 text-base font-semibold text-white shadow-md shadow-slate-300 transition duration-150 hover:bg-<?= $scheme ?>-700">Verify</button>
                        </form>
                    <?php else: ?>
                        <form method="post" class="space-y-4" id="login-form" autocomplete="on">
                            <label class="block text-sm font-semibold text-slate-700">Username
                                <input type="text" name="username" autocomplete="username" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-base text-slate-900 placeholder-slate-400 shadow-sm focus:border-<?= $scheme ?>-500 focus:outline-none focus:ring-2 focus:ring-<?= $scheme ?>-200" data-help="Enter your username">
                            </label>
                            <label class="block text-sm font-semibold text-slate-700">Password
                                <input type="password" name="password" autocomplete="current-password" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-base text-slate-900 placeholder-slate-400 shadow-sm focus:border-<?= $scheme ?>-500 focus:outline-none focus:ring-2 focus:ring-<?= $scheme ?>-200" data-help="Enter your password">
                            </label>
                            <button type="submit" aria-label="Log in" class="w-full rounded-xl bg-<?= $scheme ?>-600 py-3 text-base font-semibold text-white shadow-md shadow-slate-300 transition duration-150 hover:bg-<?= $scheme ?>-700">Login</button>
                        </form>
                    <?php endif; ?>
                </section>
            </div>
        </main>
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
