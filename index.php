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
$headingFont = $brand['heading_font'];
$bodyFont = $brand['body_font'];
$tableFont = $brand['table_font'];
$chartFont = $brand['chart_font'];
$accentWeight = $brand['accent_font_weight'];
$error = '';

$buttonColors = ['600' => $brand['brand_color'], '700' => $brand['brand_color_dark']];

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
    <link rel="stylesheet" href="frontend/login.css?v=20260815-login-refresh">
    <style>
        :root {
            --login-brand: <?= htmlspecialchars($buttonColors['600']) ?>;
            --login-brand-dark: <?= htmlspecialchars($buttonColors['700']) ?>;
        }

        .brand-action-btn {
            background-color: var(--login-brand);
            color: #ffffff;
        }

        .brand-action-btn:hover,
        .brand-action-btn:focus-visible {
            background-color: var(--login-brand-dark);
            color: #ffffff;
        }
    </style>
</head>
<body class="login-page">
    <div class="login-stage">
        <div class="login-orb login-orb--one" aria-hidden="true"></div>
        <div class="login-orb login-orb--two" aria-hidden="true"></div>
        <main class="login-main">
            <div class="login-shell">
                <section class="login-story" aria-labelledby="login-site-name">
                    <div class="login-security-badge">
                        <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                        Secure Access
                    </div>
                    <h1 id="login-site-name"><?= htmlspecialchars($siteName) ?></h1>
                    <p class="login-story-copy">Your complete financial position, organised clearly and available in an instant.</p>
                    <dl class="login-benefits">
                        <div class="login-benefit">
                            <dt class="login-benefit-icon"><i class="fa-solid fa-chart-line" aria-hidden="true"></i></dt>
                            <dd><strong>See the whole picture</strong><span>Accounts, budgets, projects and reporting in one place.</span></dd>
                        </div>
                        <div class="login-benefit">
                            <dt class="login-benefit-icon login-benefit-icon--cyan"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i></dt>
                            <dd><strong>Less financial admin</strong><span>Reusable tagging and intelligent organisation save time.</span></dd>
                        </div>
                        <div class="login-benefit">
                            <dt class="login-benefit-icon login-benefit-icon--emerald"><i class="fa-solid fa-lock" aria-hidden="true"></i></dt>
                            <dd><strong>Private by design</strong><span>Secure accounts with optional two-factor authentication.</span></dd>
                        </div>
                    </dl>
                </section>
                <section class="login-panel" aria-labelledby="login-heading">
                    <div class="login-panel-header">
                        <span>Authentication</span>
                        <span><?= $needsToken ? 'Two-Factor' : 'Login' ?></span>
                    </div>
                    <div class="login-welcome">
                        <div class="login-logo">
                            <img src="favicon.png" alt="<?= htmlspecialchars($siteName) ?> logo" />
                        </div>
                        <div>
                            <h2 id="login-heading"><?= $needsToken ? 'Two-factor verification' : 'Welcome back' ?></h2>
                            <p>Sign in to continue to your financial workspace.</p>
                        </div>
                    </div>
                    <p class="login-instruction">
                        <?= $needsToken ? 'Enter the 6-digit code from your authenticator app to complete sign in.' : 'Use your account credentials to access the ' . htmlspecialchars($siteName) . ' workspace.' ?>
                    </p>
                    <?php if ($error): ?>
                        <p class="login-error" role="alert"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><?= htmlspecialchars($error) ?></p>
                    <?php endif; ?>
                    <?php if ($needsToken): ?>
                        <form method="post" class="login-form" id="token-form" autocomplete="on">
                            <label class="login-field" for="login-token">Six-digit verification code
                                <span class="login-input-wrap"><i class="fa-solid fa-mobile-screen-button" aria-hidden="true"></i>
                                    <input id="login-token" type="text" name="token" autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocapitalize="off" autocorrect="off" spellcheck="false" enterkeyhint="done" required data-help="Enter your 6-digit code">
                                </span>
                            </label>
                            <button type="submit" aria-label="Verify code" class="brand-action-btn login-submit">Verify code <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
                        </form>
                    <?php else: ?>
                        <form method="post" class="login-form" id="login-form" autocomplete="on">
                            <label class="login-field" for="login-username">Username
                                <span class="login-input-wrap"><i class="fa-solid fa-user" aria-hidden="true"></i>
                                    <input id="login-username" type="text" name="username" autocomplete="username" autofocus required data-help="Enter your username">
                                </span>
                            </label>
                            <label class="login-field" for="login-password">Password
                                <span class="login-input-wrap"><i class="fa-solid fa-lock" aria-hidden="true"></i>
                                    <input id="login-password" type="password" name="password" autocomplete="current-password" required data-help="Enter your password">
                                </span>
                            </label>
                            <button type="submit" aria-label="Log in" class="brand-action-btn login-submit">Open dashboard <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
                        </form>
                    <?php endif; ?>
                    <p class="login-trust"><i class="fa-solid fa-lock" aria-hidden="true"></i> Secure session · Private financial workspace</p>
                </section>
            </div>
        </main>
    </div>
    <script src="frontend/js/input_help.js"></script>
    <script src="frontend/js/page_help.js"></script>
    <script src="frontend/js/overlay.js"></script>
    <script src="frontend/js/aria_tooltips.js"></script>
    <script src="frontend/js/tooltips.js"></script>
    <script src="frontend/js/fonts.js?v=20260829-expanded-fonts"></script>
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
