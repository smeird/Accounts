<?php
// Runs 'git pull' to update the application to the latest version.
require_once __DIR__ . '/../nocache.php';
header('Content-Type: application/json');
$rootDir = dirname(__DIR__, 2);
$output = [];
$returnVar = 0;


// Determine the current branch and available remotes
$branch = trim(shell_exec('cd ' . escapeshellarg($rootDir) . ' && git rev-parse --abbrev-ref HEAD 2>/dev/null'));
$remote = trim(shell_exec('cd ' . escapeshellarg($rootDir) . ' && git remote 2>/dev/null'));
$remote = $remote !== '' ? strtok($remote, "\n") : '';

if ($remote === '') {
    $output[] = 'No git remote configured';
    $returnVar = 1;
} else {
    exec(
        'cd ' . escapeshellarg($rootDir) . ' && git pull ' . escapeshellarg($remote) . ' ' . escapeshellarg($branch) . ' 2>&1',
        $output,
        $returnVar
    );
}

echo json_encode([
    'success' => $returnVar === 0,
    'output' => trim(implode("\n", $output))
]);
