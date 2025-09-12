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
$brand = Setting::getBrand();
$siteName = $brand['site_name'];
$colorScheme = $brand['color_scheme'];
$headingFont = $brand['heading_font'];
$bodyFont = $brand['body_font'];
$tableFont = $brand['table_font'];
$chartFont = $brand['chart_font'];
$accentWeight = $brand['accent_font_weight'];
$fontOptions = ['' => 'Default',
    'Arial' => 'Arial',
    'Helvetica' => 'Helvetica',
    'Times New Roman' => 'Times New Roman',
    'Georgia' => 'Georgia',
    'Courier New' => 'Courier New',
    'Verdana' => 'Verdana',
    'Trebuchet MS' => 'Trebuchet MS',
    'Garamond' => 'Garamond',
    'Roboto' => 'Roboto',
    'Open Sans' => 'Open Sans',
    'Lato' => 'Lato',
    'Montserrat' => 'Montserrat',
    'Poppins' => 'Poppins',
    'Inter' => 'Inter',
    'Comic Sans MS' => 'Comic Sans MS',
    'Bangers' => 'Bangers',
    'Caveat' => 'Caveat',
    'Dancing Script' => 'Dancing Script',
    'Fredoka' => 'Fredoka',
    'Pacifico' => 'Pacifico',
];
$weightOptions = ['' => 'Default', '100' => 'Thin', '300' => 'Light', '700' => 'Bold'];
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
    $siteName = trim($_POST['site_name'] ?? '');
    $newColorScheme = trim($_POST['color_scheme'] ?? '');
    $headingFont = trim($_POST['font_heading'] ?? '');
    $bodyFont = trim($_POST['font_body'] ?? '');
    $tableFont = trim($_POST['font_table'] ?? '');
    $chartFont = trim($_POST['font_chart'] ?? '');
    $accentWeight = trim($_POST['accent_font_weight'] ?? '');
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
    Setting::set('font_heading', $headingFont);
    Setting::set('font_body', $bodyFont);
    Setting::set('font_table', $tableFont);
    Setting::set('font_chart', $chartFont);
    Setting::set('accent_font_weight', $accentWeight);
    Log::write('Updated font settings');
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
      <style>
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
                <select name="font_heading" class="border p-2 rounded w-full" data-help="Font for headings">
                    <?php foreach ($fontOptions as $k => $v): ?>
                        <option value="<?= htmlspecialchars($k) ?>" <?= $k === $headingFont ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="block">Body Font:
                <select name="font_body" class="border p-2 rounded w-full" data-help="Font for body text">
                    <?php foreach ($fontOptions as $k => $v): ?>
                        <option value="<?= htmlspecialchars($k) ?>" <?= $k === $bodyFont ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="block">Table Font:
                <select name="font_table" class="border p-2 rounded w-full" data-help="Font for tables">
                    <?php foreach ($fontOptions as $k => $v): ?>
                        <option value="<?= htmlspecialchars($k) ?>" <?= $k === $tableFont ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="block">Chart Font:
                <select name="font_chart" class="border p-2 rounded w-full" data-help="Font for charts">
                    <?php foreach ($fontOptions as $k => $v): ?>
                        <option value="<?= htmlspecialchars($k) ?>" <?= $k === $chartFont ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="block">Accent Font Weight:
                <select name="accent_font_weight" class="border p-2 rounded w-full" data-help="Weight for accent text like search inputs">
                    <?php foreach ($weightOptions as $k => $v): ?>
                        <option value="<?= htmlspecialchars($k) ?>" <?= $k === $accentWeight ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                    <?php endforeach; ?>
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
    <script src="frontend/js/fonts.js"></script>
    <script>
      applyFonts({
        heading_font: <?= json_encode($headingFont) ?>,
        body_font: <?= json_encode($bodyFont) ?>,
        table_font: <?= json_encode($tableFont) ?>,
        chart_font: <?= json_encode($chartFont) ?>,
        accent_font_weight: <?= json_encode($accentWeight) ?>
      });
      const fontChoices = <?= json_encode(array_keys($fontOptions)) ?>;
      fontChoices.forEach(f => { if (f) window.loadFont(f); });
      document.querySelectorAll('select[name^="font_"] option').forEach(opt => {
        if (opt.value) opt.style.fontFamily = opt.value;
      });
    </script>
  </body>
</html>
