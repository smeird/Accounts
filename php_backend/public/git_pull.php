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
// required. Point it at the repository root to satisfy this requirement.
putenv('HOME=' . $rootDir);
$_SERVER['HOME'] = $rootDir;


$output = [];
$returnVar = 0;

// Prepare a git command that treats the repository directory as safe without
// relying on global configuration that requires the HOME environment variable.
$gitCmd = 'git -C ' . escapeshellarg($rootDir) . ' -c safe.directory=' . escapeshellarg($rootDir);

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
