<?php
// Runs 'git pull' to update the application to the latest version.
require_once __DIR__ . '/../nocache.php';
header('Content-Type: application/json');
// Determine the repository root. Prefer the web server's document root
// so the script operates within the deployed application directory.
$rootDir = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 2);


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
if ($remoteStatus !== 0 || trim(implode("\n", $remoteList)) === '') {
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
