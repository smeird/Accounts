<?php
// Runs 'git pull' to update the application to the latest version.
require_once __DIR__ . '/../nocache.php';
header('Content-Type: application/json');
// Determine the repository root. Prefer the web server's document root
// so the script operates within the deployed application directory.
$rootDir = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 2);
$output = [];
$returnVar = 0;

// Allow git to operate even if repository ownership differs from the running user.
exec('git config --global --add safe.directory ' . escapeshellarg($rootDir));
// Ensure a remote is configured before attempting to pull.
$remoteList = [];
$remoteStatus = 0;
exec('cd ' . escapeshellarg($rootDir) . ' && git remote 2>&1', $remoteList, $remoteStatus);
if ($remoteStatus !== 0 || trim(implode("\n", $remoteList)) === '') {
    echo json_encode([
        'success' => false,
        'output' => 'No git remote configured'
    ]);
    exit;
}
exec('cd ' . escapeshellarg($rootDir) . ' && git pull 2>&1', $output, $returnVar);

echo json_encode([
    'success' => $returnVar === 0,
    'output' => trim(implode("\n", $output))
]);
