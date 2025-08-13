<?php
// Runs 'git pull' to update the application to the latest version.
require_once __DIR__ . '/../nocache.php';
header('Content-Type: application/json');
$rootDir = dirname(__DIR__, 2);
$output = [];
$returnVar = 0;
// Allow git to operate even if repository ownership differs from the running user.
exec('git config --global --add safe.directory ' . escapeshellarg($rootDir));
exec('cd ' . escapeshellarg($rootDir) . ' && git pull 2>&1', $output, $returnVar);
echo json_encode([
    'success' => $returnVar === 0,
    'output' => trim(implode("\n", $output))
]);
