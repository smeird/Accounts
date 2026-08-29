<?php
require_once __DIR__ . '/php_backend/auth.php';
require_once __DIR__ . '/php_backend/models/Setting.php';
require_once __DIR__ . '/php_backend/models/Log.php';

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

$message = '';
$savedOpenaiToken = Setting::get('openai_api_token') ?? '';
$openaiConfigured = is_string($savedOpenaiToken) && trim($savedOpenaiToken) !== '';
$batch = Setting::get('ai_tag_batch_size') ?? '20';
$aiModel = Setting::get('ai_model') ?? 'gpt-5-nano';
$recommendedModels = [
    'gpt-5',
    'gpt-5-mini',
    'gpt-5-nano',
    'o4-mini',
    'o3',
];
$aiTemp = Setting::get('ai_temperature') ?? '1';
$aiDebug = Setting::get('ai_debug') === '1';
$retention = Setting::get('log_retention_days') ?? '30';
$timeout = (string)$timeoutSetting;
$brand = Setting::getBrand();
$siteName = $brand['site_name'];
$colorScheme = $brand['color_scheme'];
$headingFont = $brand['heading_font'];
$bodyFont = $brand['body_font'];
$tableFont = $brand['table_font'];
$chartFont = $brand['chart_font'];
$accentWeight = $brand['accent_font_weight'];
$surfaceStyle = $brand['surface_style'];
$interfaceDensity = $brand['interface_density'];
$cornerStyle = $brand['corner_style'];
$backdropStrength = $brand['backdrop_strength'];
$motionPreference = $brand['motion_preference'];
$accentBarSize = $brand['accent_bar_size'];
$pageHeaderSize = $brand['page_header_size'];
$fontOptions = ['' => 'Default',
    'Arial' => 'Arial',
    'Helvetica' => 'Helvetica',
    'Times New Roman' => 'Times New Roman',
    'Georgia' => 'Georgia',
    'Courier New' => 'Courier New',
    'JetBrains Mono' => 'JetBrains Mono',
    'Fira Code' => 'Fira Code',
    'Source Code Pro' => 'Source Code Pro',
    'IBM Plex Mono' => 'IBM Plex Mono',
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
    'Playfair Display' => 'Playfair Display',
    'Merriweather' => 'Merriweather',
    'Oswald' => 'Oswald',
    'Raleway' => 'Raleway',
    'Nunito' => 'Nunito',
    'Quicksand' => 'Quicksand',
    'Fjalla One' => 'Fjalla One',
    'Source Serif Pro' => 'Source Serif Pro',
];
$weightOptions = ['' => 'Default', '100' => 'Thin', '300' => 'Light', '700' => 'Bold'];
$surfaceOptions = [
    'glass' => ['Glass', 'Layered, softly translucent cards'],
    'paper' => ['Paper', 'Flat, crisp professional surfaces'],
];
$densityOptions = [
    'compact' => ['Compact', 'More information on screen'],
    'comfortable' => ['Comfortable', 'The balanced site default'],
    'roomy' => ['Roomy', 'More breathing space between controls'],
];
$cornerOptions = [
    'soft' => ['Soft', 'The current generous rounding'],
    'balanced' => ['Balanced', 'Subtle, practical rounding'],
    'square' => ['Square', 'A precise document-like finish'],
];
$backdropOptions = [
    'calm' => ['Calm', 'A quieter background wash'],
    'balanced' => ['Balanced', 'Clear colour without distraction'],
    'vivid' => ['Vivid', 'A stronger branded backdrop'],
];
$motionOptions = [
    'standard' => ['Standard', 'Normal transitions and reveal effects'],
    'reduced' => ['Reduced', 'Minimise non-essential movement'],
];
$accentBarSizeOptions = [
    'hairline' => ['Hairline', 'The lightest possible one-pixel edge'],
    'small' => ['Small', 'A quieter, more compact treatment'],
    'medium' => ['Medium', 'The balanced site default'],
    'large' => ['Large', 'A stronger, more prominent treatment'],
];
$pageHeaderSizeOptions = [
    'small' => ['Small', 'A quieter, more compact treatment'],
    'medium' => ['Medium', 'The balanced site default'],
    'large' => ['Large', 'A stronger, more prominent treatment'],
];
$colorOptions = [
    'indigo',
    'blue',
    'green',
    'red',
    'purple',
    'teal',
    'orange',
    'sunset',
    'ocean',
    'violet-rose',
];
$colorMap = [
    'indigo' => '#4f46e5',
    'blue'   => '#2563eb',
    'green'  => '#059669',
    'red'    => '#dc2626',
    'purple' => '#9333ea',
    'teal'   => '#0d9488',
    'orange' => '#ea580c',
    'sunset' => '#f97316',
    'ocean' => '#0891b2',
    'violet-rose' => '#8b5cf6',
];
$colorLabels = [
    'sunset' => 'Sunset (Orange → Pink)',
    'ocean' => 'Ocean (Cyan → Blue)',
    'violet-rose' => 'Violet Rose (Violet → Rose)',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $openai = trim($_POST['openai_api_token'] ?? '');
    $clearOpenai = isset($_POST['clear_openai_api_token']);
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
    $surfaceStyle = trim($_POST['surface_style'] ?? $surfaceStyle);
    $interfaceDensity = trim($_POST['interface_density'] ?? $interfaceDensity);
    $cornerStyle = trim($_POST['corner_style'] ?? $cornerStyle);
    $backdropStrength = trim($_POST['backdrop_strength'] ?? $backdropStrength);
    $motionPreference = trim($_POST['motion_preference'] ?? $motionPreference);
    $accentBarSize = trim($_POST['accent_bar_size'] ?? $accentBarSize);
    $pageHeaderSize = trim($_POST['page_header_size'] ?? $pageHeaderSize);
    if (!array_key_exists($accentWeight, $weightOptions)) {
        $accentWeight = '';
    }
    if ($clearOpenai) {
        Setting::set('openai_api_token', '');
        $openaiConfigured = false;
        Log::write('Removed OpenAI API token');
    } elseif ($openai !== '') {
        Setting::set('openai_api_token', $openai);
        $openaiConfigured = true;
        Log::write('Updated OpenAI API token');
    }
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
    if ($newColorScheme !== '' && in_array($newColorScheme, $colorOptions, true)) {
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
    Setting::set('font_accent_weight', $accentWeight);
    Log::write('Updated font settings');
    if (!array_key_exists($surfaceStyle, $surfaceOptions)) $surfaceStyle = 'glass';
    if (!array_key_exists($interfaceDensity, $densityOptions)) $interfaceDensity = 'comfortable';
    if (!array_key_exists($cornerStyle, $cornerOptions)) $cornerStyle = 'soft';
    if (!array_key_exists($backdropStrength, $backdropOptions)) $backdropStrength = 'balanced';
    if (!array_key_exists($motionPreference, $motionOptions)) $motionPreference = 'standard';
    if (!array_key_exists($accentBarSize, $accentBarSizeOptions)) $accentBarSize = 'medium';
    if (!array_key_exists($pageHeaderSize, $pageHeaderSizeOptions)) $pageHeaderSize = 'medium';
    Setting::set('surface_style', $surfaceStyle);
    Setting::set('interface_density', $interfaceDensity);
    Setting::set('corner_style', $cornerStyle);
    Setting::set('backdrop_strength', $backdropStrength);
    Setting::set('motion_preference', $motionPreference);
    Setting::set('accent_bar_size', $accentBarSize);
    Setting::set('page_header_size', $pageHeaderSize);
    Log::write('Updated interface appearance settings');
    $message = 'Settings updated.';
}

$colorHex = $colorMap[$colorScheme] ?? '#4f46e5';
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
    <script>
        window.tailwind = window.tailwind || {};
        window.tailwind.config = {};
    </script>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="frontend/cards.css">
    <link rel="stylesheet" href="frontend/operational_ui.css">
    <link rel="stylesheet" href="frontend/utility_refresh.css?v=20260825-ipad-safe-area">
    <link rel="stylesheet" href="frontend/settings.css?v=20260829-customisation">
    <link rel="icon" type="image/png" sizes="any" href="/favicon.png">
</head>
<body class="ops-body admin-refresh-page settings-page" data-api-base="php_backend/public">
    <div class="flex min-h-screen">
        <nav id="menu" class="hidden md:flex md:flex-col w-64 flex-shrink-0 bg-transparent p-6 overflow-y-auto"></nav>
        <main class="ops-main flex-1 min-w-0 overflow-x-auto">
            <section class="settings-shell" data-no-card="true">
                <?php if ($message): ?>
                    <div class="settings-saved" role="status"><i class="fas fa-circle-check" aria-hidden="true"></i><span><?= htmlspecialchars($message) ?> The new defaults are ready across the site.</span></div>
                <?php endif; ?>
                <nav class="settings-jump" aria-label="Settings sections">
                    <a href="#appearance"><i class="fas fa-palette" aria-hidden="true"></i>Appearance</a>
                    <a href="#typography"><i class="fas fa-font" aria-hidden="true"></i>Typography</a>
                    <a href="#automation"><i class="fas fa-robot" aria-hidden="true"></i>AI &amp; automation</a>
                    <a href="#security"><i class="fas fa-shield-halved" aria-hidden="true"></i>Security</a>
                </nav>

                <form method="post" id="settings-form" class="settings-form">
                    <section id="appearance" class="settings-section" data-no-card="true">
                        <header class="settings-section__header"><span class="settings-section__icon"><i class="fas fa-palette" aria-hidden="true"></i></span><div><span>Look &amp; feel</span><h2>Appearance</h2><p>Choose the visual character and information density used throughout the application.</p></div></header>
                        <div class="settings-appearance-layout">
                            <div class="settings-field-grid">
                                <label class="settings-field settings-field--wide" for="site-name"><span>Site name</span><input id="site-name" type="text" name="site_name" value="<?= htmlspecialchars($siteName) ?>" data-help="Displayed name of the website"></label>
                                <label class="settings-field" for="color-scheme"><span>Accent colour</span><select id="color-scheme" name="color_scheme" data-help="Primary colour used for actions, headings and highlights"><?php foreach ($colorOptions as $opt): ?><option value="<?= htmlspecialchars($opt) ?>" data-color="<?= htmlspecialchars($colorMap[$opt]) ?>" <?= $opt === $colorScheme ? 'selected' : '' ?>><?= htmlspecialchars($colorLabels[$opt] ?? ucfirst($opt)) ?></option><?php endforeach; ?></select></label>
                                <fieldset class="settings-field settings-field--surface"><legend>Default surface</legend><div class="settings-choice-row"><?php foreach ($surfaceOptions as $value => $details): ?><label><input type="radio" name="surface_style" value="<?= htmlspecialchars($value) ?>" <?= $value === $surfaceStyle ? 'checked' : '' ?> data-help="Choose the default surface treatment; the sidebar switch can override this on one device"><span><strong><?= htmlspecialchars($details[0]) ?></strong><small><?= htmlspecialchars($details[1]) ?></small></span></label><?php endforeach; ?></div></fieldset>
                                <label class="settings-field" for="interface-density"><span>Information density</span><select id="interface-density" name="interface_density" data-help="Adjust desktop spacing without shrinking mobile touch targets"><?php foreach ($densityOptions as $value => $details): ?><option value="<?= htmlspecialchars($value) ?>" <?= $value === $interfaceDensity ? 'selected' : '' ?>><?= htmlspecialchars($details[0]) ?> — <?= htmlspecialchars($details[1]) ?></option><?php endforeach; ?></select></label>
                                <label class="settings-field" for="corner-style"><span>Corner shape</span><select id="corner-style" name="corner_style" data-help="Choose how rounded cards and major panels appear"><?php foreach ($cornerOptions as $value => $details): ?><option value="<?= htmlspecialchars($value) ?>" <?= $value === $cornerStyle ? 'selected' : '' ?>><?= htmlspecialchars($details[0]) ?> — <?= htmlspecialchars($details[1]) ?></option><?php endforeach; ?></select></label>
                                <label class="settings-field" for="backdrop-strength"><span>Backdrop strength</span><select id="backdrop-strength" name="backdrop_strength" data-help="Control how strongly the selected accent colour appears behind the workspace"><?php foreach ($backdropOptions as $value => $details): ?><option value="<?= htmlspecialchars($value) ?>" <?= $value === $backdropStrength ? 'selected' : '' ?>><?= htmlspecialchars($details[0]) ?> — <?= htmlspecialchars($details[1]) ?></option><?php endforeach; ?></select></label>
                                <label class="settings-field" for="accent-bar-size"><span>Top colour bar</span><select id="accent-bar-size" name="accent_bar_size" data-help="Choose the thickness of the coloured top edge on page headers and primary dashboard heroes"><?php foreach ($accentBarSizeOptions as $value => $details): ?><option value="<?= htmlspecialchars($value) ?>" <?= $value === $accentBarSize ? 'selected' : '' ?>><?= htmlspecialchars($details[0]) ?> — <?= htmlspecialchars($details[1]) ?></option><?php endforeach; ?></select></label>
                                <label class="settings-field" for="page-header-size"><span>Page header size</span><select id="page-header-size" name="page_header_size" data-help="Adjust the title scale and vertical space used by page headers"><?php foreach ($pageHeaderSizeOptions as $value => $details): ?><option value="<?= htmlspecialchars($value) ?>" <?= $value === $pageHeaderSize ? 'selected' : '' ?>><?= htmlspecialchars($details[0]) ?> — <?= htmlspecialchars($details[1]) ?></option><?php endforeach; ?></select></label>
                                <label class="settings-field" for="motion-preference"><span>Interface motion</span><select id="motion-preference" name="motion_preference" data-help="Reduce decorative animation and transitions across the site"><?php foreach ($motionOptions as $value => $details): ?><option value="<?= htmlspecialchars($value) ?>" <?= $value === $motionPreference ? 'selected' : '' ?>><?= htmlspecialchars($details[0]) ?> — <?= htmlspecialchars($details[1]) ?></option><?php endforeach; ?></select></label>
                            </div>
                            <aside id="appearance-preview" class="settings-preview" aria-label="Live appearance preview">
                                <div class="settings-preview__bar"><span><i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i>Live preview</span><small>Updates before you save</small></div>
                                <div class="settings-preview__canvas">
                                    <div class="settings-preview__header"><span></span><div><strong>Financial overview</strong><small>Your money, clearly organised</small></div></div>
                                    <div class="settings-preview__metrics"><article><small>Available</small><strong>£12,480</strong></article><article><small>This month</small><strong>£2,146</strong></article></div>
                                    <div class="settings-preview__table"><div><strong>Mortgage</strong><span>Fixed costs</span><b>£1,840</b></div><div><strong>Groceries</strong><span>Essentials</span><b>£286</b></div></div>
                                </div>
                            </aside>
                        </div>
                    </section>

                    <section id="typography" class="settings-section" data-no-card="true">
                        <header class="settings-section__header"><span class="settings-section__icon settings-section__icon--violet"><i class="fas fa-font" aria-hidden="true"></i></span><div><span>Reading character</span><h2>Typography</h2><p>Give headings, everyday copy, tables and charts their own clear voice.</p></div></header>
                        <div class="settings-field-grid settings-field-grid--fonts">
                            <?php foreach ([['font_heading', 'Heading font', $headingFont, 'font-preview-heading', 'The shape of page titles and section headings.'], ['font_body', 'Body font', $bodyFont, 'font-preview-body', 'Everyday guidance and interface copy.'], ['font_table', 'Table font', $tableFont, 'font-preview-table', 'Dense financial evidence and amounts.'], ['font_chart', 'Chart font', $chartFont, 'font-preview-chart', 'Labels, legends and chart annotations.']] as $fontField): ?>
                                <label class="settings-field" for="<?= htmlspecialchars($fontField[0]) ?>"><span><?= htmlspecialchars($fontField[1]) ?></span><select id="<?= htmlspecialchars($fontField[0]) ?>" name="<?= htmlspecialchars($fontField[0]) ?>" data-help="<?= htmlspecialchars($fontField[4]) ?>" data-preview-target="<?= htmlspecialchars($fontField[3]) ?>"><?php foreach ($fontOptions as $k => $v): ?><option value="<?= htmlspecialchars($k) ?>" <?= $k === $fontField[2] ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option><?php endforeach; ?></select><p id="<?= htmlspecialchars($fontField[3]) ?>" class="settings-font-preview"><?= htmlspecialchars($fontField[4]) ?> £1,234.56</p></label>
                            <?php endforeach; ?>
                            <label class="settings-field settings-field--wide" for="accent-font-weight"><span>Accent font weight</span><select id="accent-font-weight" name="accent_font_weight" data-help="Weight for accent text such as search inputs and highlights" data-preview-target="font-preview-weight"><?php foreach ($weightOptions as $k => $v): ?><option value="<?= htmlspecialchars($k) ?>" <?= $k === $accentWeight ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option><?php endforeach; ?></select><p id="font-preview-weight" class="settings-font-preview">Search, filters and important highlights.</p></label>
                        </div>
                    </section>

                    <section id="automation" class="settings-section" data-no-card="true">
                        <header class="settings-section__header"><span class="settings-section__icon settings-section__icon--cyan"><i class="fas fa-robot" aria-hidden="true"></i></span><div><span>Assisted organisation</span><h2>AI &amp; automation</h2><p>Control how AI-assisted tagging and reviews connect to this installation.</p></div></header>
                        <div class="settings-field-grid">
                            <div class="settings-field settings-field--wide"><label for="openai-token">OpenAI API token</label><input id="openai-token" type="password" name="openai_api_token" value="" autocomplete="new-password" placeholder="<?= $openaiConfigured ? 'Token configured — enter a replacement' : 'Enter an API token' ?>" data-help="Enter a new token to replace the configured OpenAI API token"><small><?= $openaiConfigured ? 'A token is configured. Its saved value is never returned to the browser.' : 'No token is currently configured.' ?></small><?php if ($openaiConfigured): ?><label class="settings-check"><input type="checkbox" name="clear_openai_api_token" value="1"><span>Remove the saved token</span></label><?php endif; ?></div>
                            <label class="settings-field" for="ai-tag-batch"><span>AI tag batch size</span><input id="ai-tag-batch" type="number" min="1" name="ai_tag_batch_size" value="<?= htmlspecialchars($batch) ?>" data-help="How many transactions to submit for AI tagging at once"></label>
                            <label class="settings-field" for="ai-temperature"><span>AI temperature</span><input id="ai-temperature" type="number" min="0" max="2" step="0.1" name="ai_temperature" value="<?= htmlspecialchars($aiTemp) ?>" data-help="Creativity level for AI responses"></label>
                            <label class="settings-field settings-field--wide" for="ai-model-select"><span>AI model</span><div class="settings-model-row"><select id="ai-model-select" data-help="Choose from recommended models or models available to your API token"><?php foreach ($recommendedModels as $modelOption): ?><option value="<?= htmlspecialchars($modelOption) ?>" <?= $modelOption === $aiModel ? 'selected' : '' ?>><?= htmlspecialchars($modelOption) ?></option><?php endforeach; ?><?php if (!in_array($aiModel, $recommendedModels, true) && $aiModel !== ''): ?><option value="<?= htmlspecialchars($aiModel) ?>" selected><?= htmlspecialchars($aiModel) ?> (Saved)</option><?php endif; ?><option value="__custom__">Custom model…</option></select><button type="button" id="refresh-models" class="settings-secondary-button" aria-label="Refresh available AI models"><i class="fas fa-rotate" aria-hidden="true"></i>Refresh list</button></div><input type="text" id="ai-model-input" name="ai_model" value="<?= htmlspecialchars($aiModel) ?>" data-help="Model name for OpenAI responses"><small id="ai-model-status" role="status"></small></label>
                            <label class="settings-toggle settings-field--wide"><span><strong>AI debug details</strong><small>Show submitted prompts and AI responses on supported pages for troubleshooting.</small></span><input type="checkbox" name="ai_debug" value="1" <?= $aiDebug ? 'checked' : '' ?> data-help="Show AI request and response details on pages for troubleshooting"></label>
                        </div>
                    </section>

                    <section id="security" class="settings-section" data-no-card="true">
                        <header class="settings-section__header"><span class="settings-section__icon settings-section__icon--emerald"><i class="fas fa-shield-halved" aria-hidden="true"></i></span><div><span>Safe operation</span><h2>Security &amp; maintenance</h2><p>Choose sensible retention and inactivity limits for this installation.</p></div></header>
                        <div class="settings-field-grid">
                            <label class="settings-field" for="log-retention"><span>Log retention days</span><input id="log-retention" type="number" min="1" name="log_retention_days" value="<?= htmlspecialchars($retention) ?>" data-help="Automatically prune logs older than this many days"><small>Older operational records can be pruned from the System Log.</small></label>
                            <label class="settings-field" for="session-timeout"><span>Automatic logout</span><div class="settings-suffix"><input id="session-timeout" type="number" min="0" name="session_timeout_minutes" value="<?= htmlspecialchars($timeout) ?>" data-help="Minutes of inactivity before automatic logout"><span>minutes</span></div><small>Use 0 to keep the session open until explicit sign-out.</small></label>
                        </div>
                    </section>

                    <div class="admin-actions settings-actions"><span><i class="fas fa-circle-info" aria-hidden="true"></i>Appearance choices become the default for every signed-in page.</span><button type="submit" class="settings-save" style="--settings-accent:<?= htmlspecialchars($colorHex, ENT_QUOTES, 'UTF-8') ?>" aria-label="Save settings"><i class="fas fa-floppy-disk" aria-hidden="true"></i>Save settings</button></div>
                </form>
            </section>
        </main>
    </div>
    <script src="frontend/js/page_header.js"></script>
    <script>window.renderPageHeader(document.querySelector('main.ops-main'), { title: 'Settings', breadcrumb: 'System', subtitle: 'Shape the workspace, tune automation, and keep the installation secure.' });</script>
    <script src="frontend/js/menu.js"></script>
    <script src="frontend/js/input_help.js"></script>
    <script src="frontend/js/page_help.js"></script>
    <script src="frontend/js/overlay.js"></script>
    <script src="frontend/js/aria_tooltips.js"></script>
    <script src="frontend/js/tooltips.js"></script>
    <script src="frontend/js/fonts.js?v=20260811-font-weights"></script>
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

      const updateFontPreview = (selectElement) => {
        if (!selectElement || !selectElement.dataset.previewTarget) {
            return;
        }
        const previewElement = document.getElementById(selectElement.dataset.previewTarget);
        if (!previewElement) {
            return;
        }
        previewElement.style.fontFamily = selectElement.value || '';
      };

      document.querySelectorAll('select[name^="font_"]').forEach(selectElement => {
        updateFontPreview(selectElement);
        selectElement.addEventListener('change', () => updateFontPreview(selectElement));
      });

      const weightSelect = document.querySelector('select[name="accent_font_weight"]');
      const weightPreview = document.getElementById('font-preview-weight');
      const updateWeightPreview = () => {
        if (!weightSelect || !weightPreview) {
            return;
        }
        weightPreview.style.fontWeight = weightSelect.value || '';
      };

      updateWeightPreview();
      if (weightSelect) {
        weightSelect.addEventListener('change', updateWeightPreview);
      }

      const settingsForm = document.getElementById('settings-form');
      const appearancePreview = document.getElementById('appearance-preview');
      const previewCanvas = appearancePreview?.querySelector('.settings-preview__canvas');
      const colorSelect = document.getElementById('color-scheme');
      const densitySelect = document.getElementById('interface-density');
      const cornerSelect = document.getElementById('corner-style');
      const backdropSelect = document.getElementById('backdrop-strength');
      const accentBarSelect = document.getElementById('accent-bar-size');
      const pageHeaderSelect = document.getElementById('page-header-size');
      const siteNameInput = document.getElementById('site-name');

      const colorToRgb = color => {
        const clean = String(color || '').replace('#', '');
        if (!/^[0-9a-f]{6}$/i.test(clean)) return '79,70,229';
        return `${parseInt(clean.slice(0, 2), 16)},${parseInt(clean.slice(2, 4), 16)},${parseInt(clean.slice(4, 6), 16)}`;
      };

      const updateAppearancePreview = () => {
        if (!appearancePreview || !previewCanvas) return;
        const surface = document.querySelector('input[name="surface_style"]:checked')?.value || 'glass';
        const density = densitySelect?.value || 'comfortable';
        const corners = cornerSelect?.value || 'soft';
        const backdrop = backdropSelect?.value || 'balanced';
        const accentBar = accentBarSelect?.value || 'medium';
        const pageHeader = pageHeaderSelect?.value || 'medium';
        const accent = colorSelect?.selectedOptions[0]?.dataset.color || '#4f46e5';
        const rgb = colorToRgb(accent);
        const alpha = backdrop === 'calm' ? .07 : (backdrop === 'vivid' ? .27 : .16);
        appearancePreview.classList.toggle('is-paper', surface === 'paper');
        appearancePreview.classList.toggle('is-compact', density === 'compact');
        appearancePreview.classList.toggle('is-roomy', density === 'roomy');
        appearancePreview.classList.toggle('is-balanced-corners', corners === 'balanced');
        appearancePreview.classList.toggle('is-square-corners', corners === 'square');
        appearancePreview.classList.toggle('is-calm', backdrop === 'calm');
        appearancePreview.classList.toggle('is-vivid', backdrop === 'vivid');
        appearancePreview.classList.toggle('is-accent-small', accentBar === 'small');
        appearancePreview.classList.toggle('is-accent-hairline', accentBar === 'hairline');
        appearancePreview.classList.toggle('is-accent-large', accentBar === 'large');
        appearancePreview.classList.toggle('is-header-small', pageHeader === 'small');
        appearancePreview.classList.toggle('is-header-large', pageHeader === 'large');
        appearancePreview.style.setProperty('--site-brand', accent);
        previewCanvas.style.background = `linear-gradient(145deg,rgba(${rgb},${alpha}),rgba(6,182,212,${alpha * .45}),rgba(255,255,255,.98) 76%)`;
        const previewTitle = appearancePreview.querySelector('.settings-preview__header strong');
        if (previewTitle) previewTitle.textContent = siteNameInput?.value.trim() || 'Financial overview';
      };

      document.querySelectorAll('#appearance input,#appearance select').forEach(control => {
        control.addEventListener('input', updateAppearancePreview);
        control.addEventListener('change', updateAppearancePreview);
      });
      updateAppearancePreview();

      if (settingsForm) {
        settingsForm.addEventListener('submit', () => {
          const surface = document.querySelector('input[name="surface_style"]:checked')?.value || 'glass';
          localStorage.setItem('professionalThemeEnabled', String(surface === 'paper'));
        });
      }

      const modelSelect = document.getElementById('ai-model-select');
      const modelInput = document.getElementById('ai-model-input');
      const refreshModelsBtn = document.getElementById('refresh-models');
      const modelStatus = document.getElementById('ai-model-status');

      const syncModelSelect = () => {
        if (!modelSelect || !modelInput) {
            return;
        }
        const hasOption = Array.from(modelSelect.options).some(option => option.value === modelInput.value);
        modelSelect.value = hasOption ? modelInput.value : '__custom__';
      };

      if (modelSelect && modelInput) {
        modelSelect.addEventListener('change', () => {
            if (modelSelect.value !== '__custom__') {
                modelInput.value = modelSelect.value;
            }
        });
        modelInput.addEventListener('input', syncModelSelect);
        syncModelSelect();
      }

      const renderModelOptions = (models) => {
        if (!modelSelect || !Array.isArray(models)) {
            return;
        }
        const selectedValue = modelInput ? modelInput.value : '';
        const staticValues = <?= json_encode($recommendedModels) ?>;
        const merged = Array.from(new Set(staticValues.concat(models))).sort();
        modelSelect.innerHTML = '';
        merged.forEach(model => {
            const option = document.createElement('option');
            option.value = model;
            option.textContent = model;
            if (model === selectedValue) {
                option.selected = true;
            }
            modelSelect.appendChild(option);
        });
        const customOption = document.createElement('option');
        customOption.value = '__custom__';
        customOption.textContent = 'Custom model…';
        modelSelect.appendChild(customOption);
        syncModelSelect();
      };

      if (refreshModelsBtn) {
        refreshModelsBtn.addEventListener('click', async () => {
            if (modelStatus) modelStatus.textContent = 'Loading models…';
            try {
                const res = await fetch('php_backend/public/ai_models.php');
                const data = await res.json();
                if (!res.ok || data.error) {
                    throw new Error(data.error || 'Failed to load models');
                }
                renderModelOptions(data.models || []);
                if (modelStatus) modelStatus.textContent = 'Model list updated.';
            } catch (error) {
                if (modelStatus) modelStatus.textContent = error.message;
            }
        });
      }
    </script>
  </body>
</html>
