<?php
ini_set('session.cookie_secure', '1');
session_start();
require_once __DIR__ . '/php_backend/models/Setting.php';
require_once __DIR__ . '/php_backend/models/Log.php';
require_once __DIR__ . '/php_backend/nocache.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$message = '';
$openai = Setting::get('openai_api_token') ?? '';
$batch = Setting::get('ai_tag_batch_size') ?? '20';
$aiModel = Setting::get('ai_model') ?? 'gpt-5-nano';
$aiTemp = Setting::get('ai_temperature') ?? '1';
$aiDebug = Setting::get('ai_debug') === '1';
$retention = Setting::get('log_retention_days') ?? '30';
$timeout = Setting::get('session_timeout_minutes') ?? '0';
$fontSettings = Setting::getFonts();
$fontHeading = $fontSettings['heading'];
$fontBody = $fontSettings['body'];
$fontAccent = $fontSettings['accent'];
$fontAccentWeight = $fontSettings['accent_weight'];
$fontTable = $fontSettings['table'];
$brand = Setting::getBrand();
$siteName = $brand['site_name'];
$colorScheme = $brand['color_scheme'];
$fontOptions = [
    'Roboto', 'Inter', 'Source Sans Pro', 'Montserrat', 'Open Sans', 'Lato',
    'Nunito', 'Poppins', 'Raleway', 'Work Sans', 'Quicksand',
    'Karla', 'Fira Sans', 'Noto Sans'
];
$colorOptions = ['indigo', 'blue', 'green', 'red', 'purple', 'teal', 'orange'];
$colorMap = [
    'indigo' => '#4f46e5',
    'blue'   => '#2563eb',
    'green'  => '#059669',
    'red'    => '#dc2626',
    'purple' => '#9333ea',
    'teal'   => '#0d9488',
    'orange' => '#ea580c',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $openai = trim($_POST['openai_api_token'] ?? '');
    $batch = trim($_POST['ai_tag_batch_size'] ?? '');
    $aiModel = trim($_POST['ai_model'] ?? '');
    $aiTemp = trim($_POST['ai_temperature'] ?? '');
    $aiDebug = isset($_POST['ai_debug']);
    $retention = trim($_POST['log_retention_days'] ?? '');
    $timeout = trim($_POST['session_timeout_minutes'] ?? '');
    $fontHeading = trim($_POST['font_heading'] ?? '');
    $fontBody = trim($_POST['font_body'] ?? '');
    $fontAccent = trim($_POST['font_accent'] ?? '');
    $fontAccentWeight = trim($_POST['font_accent_weight'] ?? '');
    $fontTable = trim($_POST['font_table'] ?? '');
    $siteName = trim($_POST['site_name'] ?? '');
    $newColorScheme = trim($_POST['color_scheme'] ?? '');
    Setting::set('openai_api_token', $openai);
    Log::write('Updated OpenAI API token');
    if ($batch !== '') {
        Setting::set('ai_tag_batch_size', $batch);
        Log::write('Updated AI tag batch size');
    }
    if ($aiModel !== '') {
        Setting::set('ai_model', $aiModel);
        Log::write('Updated AI model');
    }
    if ($aiTemp !== '') {
        Setting::set('ai_temperature', $aiTemp);
        Log::write('Updated AI temperature');
    }
    Setting::set('ai_debug', $aiDebug ? '1' : '0');
    Log::write('Updated AI debug mode');
    if ($retention !== '') {
        Setting::set('log_retention_days', $retention);
        Log::write('Updated log retention days');
    }
    if ($timeout !== '') {
        Setting::set('session_timeout_minutes', $timeout);
        Log::write('Updated session timeout minutes');
    }
    if ($fontHeading !== '') {
        Setting::set('font_heading', $fontHeading);
        Log::write('Updated heading font');
    }
    if ($fontBody !== '') {
        Setting::set('font_body', $fontBody);
        Log::write('Updated body font');
    }
    if ($fontAccent !== '') {
        Setting::set('font_accent', $fontAccent);
        Log::write('Updated accent font');
    }
    if ($fontAccentWeight !== '') {
        Setting::set('font_accent_weight', $fontAccentWeight);
        Log::write('Updated accent font weight');
    }
    if ($fontTable !== '') {
        Setting::set('font_table', $fontTable);
        Log::write('Updated table font');
    }
    if ($siteName !== '') {
        Setting::set('site_name', $siteName);
        Log::write('Updated site name');
    }
    if ($newColorScheme !== '') {
        if ($newColorScheme !== $colorScheme) {
            Setting::set('color_scheme', $newColorScheme);
            Log::write('Updated color scheme');
            $colorScheme = $newColorScheme;
        }
    }
    $message = 'Settings updated.';
}

$colorHex = $colorMap[$colorScheme] ?? '#4f46e5';
$text600 = "text-{$colorScheme}-600";
$text700 = "text-{$colorScheme}-700";
$text900 = "text-{$colorScheme}-900";
$bg600 = "bg-{$colorScheme}-600";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings</title>
    <script>
        window.tailwind = window.tailwind || {};
        window.tailwind.config = {};
    </script>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/png" sizes="any" href="/favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=<?= urlencode($fontHeading) ?>:wght@700&family=<?= urlencode($fontBody) ?>:wght@400&family=<?= urlencode($fontAccent) ?>:wght@<?= urlencode($fontAccentWeight) ?>&family=<?= urlencode($fontTable) ?>:wght@400&display=swap" rel="stylesheet">
    <style>
        body { font-family: '<?= htmlspecialchars($fontBody, ENT_QUOTES) ?>', sans-serif; }
        h1, h2, h3, h4, h5, h6 { font-family: '<?= htmlspecialchars($fontHeading, ENT_QUOTES) ?>', sans-serif; font-weight: 700; }
        button, .accent { font-family: '<?= htmlspecialchars($fontAccent, ENT_QUOTES) ?>', sans-serif; font-weight: <?= htmlspecialchars($fontAccentWeight, ENT_QUOTES) ?>; }
        a { transition: color 0.2s ease; }
        a:hover { color: <?= $colorHex ?>; }
        button { transition: transform 0.1s ease, box-shadow 0.1s ease; }
        button:hover { transform: translateY(-2px); box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
    </style>
</head>
<body class="min-h-screen bg-gray-50 p-6" data-api-base="php_backend/public">
    <div class="max-w-4xl mx-auto bg-white p-6 rounded shadow border border-gray-400">
        <i class="fas fa-cogs <?= $text600 ?> text-6xl mb-4 block mx-auto"></i>
        <div class="uppercase <?= $text900 ?> text-[0.6rem] mb-1">ADMIN TOOLS / SYSTEM SETTINGS</div>
        <h1 class="text-2xl font-semibold mb-4 <?= $text700 ?>">System Settings</h1>
        <p class="mb-4">Adjust application configuration values.</p>
        <p class="mb-4"><a href="logout.php" class="<?= $text600 ?> hover:underline">Logout</a> | <a href="frontend/index.html" class="<?= $text600 ?> hover:underline">Home</a></p>
        <?php if ($message): ?>
            <p class="mb-4 text-green-600"><?= htmlspecialchars($message) ?></p>
        <?php endif; ?>
        <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label class="block">OpenAI API Token:
                <input type="text" name="openai_api_token" value="<?= htmlspecialchars($openai) ?>" class="border p-2 rounded w-full" data-help="Token used for AI tagging">
            </label>
            <label class="block">AI Tag Batch Size:
                <input type="number" name="ai_tag_batch_size" value="<?= htmlspecialchars($batch) ?>" class="border p-2 rounded w-full" data-help="How many transactions to submit for AI tagging at once">
            </label>
            <label class="block">AI Model:
                <input type="text" name="ai_model" value="<?= htmlspecialchars($aiModel) ?>" class="border p-2 rounded w-full" data-help="Model name for OpenAI responses">
            </label>
            <label class="block">AI Temperature:
                <input type="number" step="0.1" name="ai_temperature" value="<?= htmlspecialchars($aiTemp) ?>" class="border p-2 rounded w-full" data-help="Creativity level for AI responses">
            </label>
            <label class="block">AI Debug Mode:
                <input type="checkbox" name="ai_debug" value="1" <?= $aiDebug ? 'checked' : '' ?> class="ml-2" data-help="Show AI request and response details on pages for troubleshooting">
            </label>
            <label class="block">Log Retention Days:
                <input type="number" name="log_retention_days" value="<?= htmlspecialchars($retention) ?>" class="border p-2 rounded w-full" data-help="Automatically prune logs older than this many days">
            </label>
            <label class="block">Auto-Logout Minutes:
                <input type="number" name="session_timeout_minutes" value="<?= htmlspecialchars($timeout) ?>" class="border p-2 rounded w-full" data-help="Minutes of inactivity before automatic logout">
            </label>
            <label class="block">Site Name:
                <input type="text" name="site_name" value="<?= htmlspecialchars($siteName) ?>" class="border p-2 rounded w-full" data-help="Displayed name of the website">
            </label>
            <label class="block">Color Scheme:
                <select name="color_scheme" class="border p-2 rounded w-full" data-help="Primary Tailwind color">
                    <?php foreach ($colorOptions as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>" <?= $opt === $colorScheme ? 'selected' : '' ?>><?= ucfirst($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="block">Heading Font:
                <select name="font_heading" class="border p-2 rounded w-full" data-help="Font used for headings">
                    <?php foreach ($fontOptions as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>" <?= $opt === $fontHeading ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="block">Body Font:
                <select name="font_body" class="border p-2 rounded w-full" data-help="Font used for body text">
                    <?php foreach ($fontOptions as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>" <?= $opt === $fontBody ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="block">Accent Font:
                <select name="font_accent" class="border p-2 rounded w-full" data-help="Font used for buttons and accents">
                    <?php foreach ($fontOptions as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>" <?= $opt === $fontAccent ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="block">Table Font:
                <select name="font_table" class="border p-2 rounded w-full" data-help="Font used for tables">
                    <?php foreach ($fontOptions as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>" <?= $opt === $fontTable ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="block">Accent Font Weight:
                <select name="font_accent_weight" class="border p-2 rounded w-full" data-help="Weight for buttons and accents">
                    <option value="300" <?= $fontAccentWeight === '300' ? 'selected' : '' ?>>Light (300)</option>
                    <option value="100" <?= $fontAccentWeight === '100' ? 'selected' : '' ?>>Very Thin (100)</option>
                </select>
            </label>
            <button type="submit" class="<?= $bg600 ?> text-white px-4 py-2 rounded md:col-span-2" aria-label="Save Settings"><i class="fas fa-save inline w-4 h-4 mr-2"></i>Save Settings</button>
        </form>
    </div>
    <script src="frontend/js/input_help.js"></script>
    <script src="frontend/js/page_help.js"></script>
    <script src="frontend/js/overlay.js"></script>
    <script src="frontend/js/aria_tooltips.js"></script>
    <script src="frontend/js/tooltips.js"></script>
</body>
</html>
