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
$retention = Setting::get('log_retention_days') ?? '30';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $openai = trim($_POST['openai_api_token'] ?? '');
    $batch = trim($_POST['ai_tag_batch_size'] ?? '');
    $retention = trim($_POST['log_retention_days'] ?? '');
    Setting::set('openai_api_token', $openai);
    Log::write('Updated OpenAI API token');
    if ($batch !== '') {
        Setting::set('ai_tag_batch_size', $batch);
        Log::write('Updated AI tag batch size');
    }
    if ($retention !== '') {
        Setting::set('log_retention_days', $retention);
        Log::write('Updated log retention days');
    }
    $message = 'Settings updated.';
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
    <title>System Settings</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/svg+xml" sizes="any" href="/favicon.svg">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700&family=Inter:wght@400&family=Source+Sans+Pro:wght@300&display=swap" rel="stylesheet">
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
        <i class="fas fa-cogs text-indigo-600 text-6xl mb-4 block mx-auto"></i>
        <div class="uppercase text-indigo-900 text-[0.6rem] mb-1">ADMIN TOOLS / SYSTEM SETTINGS</div>
        <h1 class="text-2xl font-semibold mb-4 text-indigo-700">System Settings</h1>
        <p class="mb-4">Adjust application configuration values.</p>
        <p class="mb-4"><a href="logout.php" class="text-indigo-600 hover:underline">Logout</a> | <a href="frontend/index.html" class="text-indigo-600 hover:underline">Home</a></p>
        <?php if ($message): ?>
            <p class="mb-4 text-green-600"><?= htmlspecialchars($message) ?></p>
        <?php endif; ?>
        <form method="post" class="space-y-4">
            <label class="block">OpenAI API Token:
                <input type="text" name="openai_api_token" value="<?= htmlspecialchars($openai) ?>" class="border p-2 rounded w-full" data-help="Token used for AI tagging">
            </label>
            <label class="block">AI Tag Batch Size:
                <input type="number" name="ai_tag_batch_size" value="<?= htmlspecialchars($batch) ?>" class="border p-2 rounded w-full" data-help="How many transactions to submit for AI tagging at once">
            </label>
            <label class="block">Log Retention Days:
                <input type="number" name="log_retention_days" value="<?= htmlspecialchars($retention) ?>" class="border p-2 rounded w-full" data-help="Automatically prune logs older than this many days">
            </label>
            <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded"><i class="fas fa-save inline w-4 h-4 mr-2"></i>Save Settings</button>
        </form>
    </div>
    <script src="frontend/js/input_help.js"></script>
    <script src="frontend/js/keyboard_hints.js"></script>
    <script src="frontend/js/page_help.js"></script>
    <script src="frontend/js/overlay.js"></script>
</body>
</html>
