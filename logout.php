<?php
// Log out the current user and show confirmation page.
require_once __DIR__ . '/php_backend/auth.php';
require_once __DIR__ . '/php_backend/models/Log.php';
require_once __DIR__ . '/php_backend/models/Setting.php';

if (isset($_SESSION['user_id'])) {
    $reason = isset($_GET['timeout']) ? ' (timeout)' : '';
    Log::write('User ' . $_SESSION['user_id'] . ' logged out' . $reason);
}

$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
    session_unset();
    session_destroy();
}

if (isset($_GET['timeout'])) {
    header('Location: index.php');
    exit;
}
$brand = Setting::getBrand();
$siteName = $brand['site_name'];
$colorScheme = $brand['color_scheme'];
$headingFont = $brand['heading_font'];
$bodyFont = $brand['body_font'];
$tableFont = $brand['table_font'];
$chartFont = $brand['chart_font'];
$accentWeight = $brand['accent_font_weight'];
$buttonClass = "bg-{$colorScheme}-500/90 hover:bg-{$colorScheme}-400/90";
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
    <link rel="icon" type="image/png" sizes="any" href="/favicon.png">
    <script>
        window.tailwind = window.tailwind || {};
        window.tailwind.config = {};
    </script>

    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="relative min-h-screen flex items-center justify-center overflow-hidden bg-gradient-to-br from-slate-950 via-slate-900 to-slate-950 text-slate-100">
    <div class="pointer-events-none absolute inset-0 overflow-hidden">
        <div class="absolute -top-32 -left-24 h-80 w-80 rounded-full bg-gradient-to-br from-emerald-400/40 via-teal-400/30 to-transparent blur-3xl"></div>
        <div class="absolute bottom-[-5rem] left-1/2 h-72 w-[32rem] -translate-x-1/2 rounded-full bg-gradient-to-r from-white/20 via-white/10 to-transparent blur-[120px]"></div>
        <div class="absolute top-1/2 right-[-6rem] h-96 w-96 -translate-y-1/2 rounded-full bg-gradient-to-tr from-sky-400/30 via-indigo-400/20 to-transparent blur-3xl"></div>
    </div>
    <div class="relative z-10 w-full max-w-md px-6">
        <div class="relative overflow-hidden rounded-3xl border border-white/20 bg-white/10 p-8 text-center shadow-[0_35px_60px_-15px_rgba(15,23,42,0.9)] backdrop-blur-2xl">
            <div class="absolute inset-0 -z-10 rounded-3xl bg-gradient-to-br from-white/20 via-transparent to-white/5"></div>
            <div class="absolute inset-[1px] -z-10 rounded-[1.45rem] border border-white/10"></div>
            <div class="mb-6 flex items-center justify-between text-xs uppercase tracking-[0.3em] text-white/70">
                <span>Session</span>
                <span>Ended</span>
            </div>
            <div class="mx-auto mb-6 flex h-20 w-20 items-center justify-center rounded-2xl border border-white/30 bg-white/10 shadow-inner">
                <img src="favicon.png" alt="<?= htmlspecialchars($siteName) ?> logo" class="h-12 w-12" />
            </div>
            <h1 class="mb-3 text-3xl font-semibold text-white">You've signed out</h1>
            <p class="mb-6 text-sm text-white/80">Your <?= htmlspecialchars($siteName) ?> session has closed securely. You can return to the login screen whenever you're ready.</p>
            <a href="index.php" aria-label="Return to the login page" class="inline-flex w-full items-center justify-center rounded-xl <?= $buttonClass ?> px-4 py-3 text-base font-semibold text-white shadow-[0_15px_35px_rgba(15,23,42,0.45)] transition duration-150 hover:-translate-y-0.5">Return to Login</a>
        </div>
    </div>
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
