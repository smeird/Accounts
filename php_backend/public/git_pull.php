<?php
// Runs 'git pull' to update the application to the latest version.
require_once __DIR__ . '/../nocache.php';
header('Content-Type: application/json');
// Determine the repository root. Start from the web server's document root if
// available, but walk up the directory tree until a `.git` folder is found so
// Git commands always run from the actual repository root.
$rootDir = realpath($_SERVER['DOCUMENT_ROOT'] ?? __DIR__);
if ($rootDir === false) {
    $rootDir = dirname(__DIR__, 2);
}

// Traverse upwards to locate the git repository
$repoDir = $rootDir;
while ($repoDir !== '/' && !is_dir($repoDir . '/.git')) {
    $parent = dirname($repoDir);
    if ($parent === $repoDir) {
        break;
    }
    $repoDir = $parent;
}

if (!is_dir($repoDir . '/.git')) {
    echo json_encode([
        'success' => false,
        'output' => 'Git repository not found'
    ]);
    exit;
}

$rootDir = $repoDir;


// Git expects a HOME environment variable even when no global configuration is
// required. Point it at a temporary directory so `git config --global` has a
// safe place to write to and doesn't pollute the repository.
$homeDir = sys_get_temp_dir();
putenv('HOME=' . $homeDir);
$_SERVER['HOME'] = $homeDir;

// Mark the repository as a safe directory if it has not already been whitelisted
// to avoid "dubious ownership" errors when running commands.
$safeCheck = [];
$safeStatus = 0;
exec('git config --global --get safe.directory ' . escapeshellarg($rootDir) . ' 2>&1', $safeCheck, $safeStatus);
if ($safeStatus !== 0) {
    exec('git config --global --add safe.directory ' . escapeshellarg($rootDir) . ' 2>&1');
}

$output = [];
$returnVar = 0;

// Prepare a git command rooted at the repository.
$gitCmd = 'git -C ' . escapeshellarg($rootDir);

// Ensure a remote is configured before attempting to pull.
$remoteList = [];
$remoteStatus = 0;
exec($gitCmd . ' remote 2>&1', $remoteList, $remoteStatus);
$remoteOutput = trim(implode("\n", $remoteList));
if ($remoteStatus !== 0) {
    echo json_encode([
        'success' => false,
        'output' => $remoteOutput
    ]);
    exit;
}
if ($remoteOutput === '') {
    echo json_encode([
        'success' => false,
        'output' => 'No git remote configured'
    ]);
    exit;
}
exec($gitCmd . ' pull 2>&1', $output, $returnVar);

echo json_encode([
    'success' => $returnVar === 0,
    'output' => trim(implode("\n", $output))
]);
