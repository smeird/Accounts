<?php
// Outputs the current git commit hash for version display.
header('Content-Type: application/json');
$rootDir = dirname(__DIR__, 2);
$commitHash = trim(shell_exec('git -C ' . escapeshellarg($rootDir) . ' rev-parse --short HEAD'));
echo json_encode(['version' => $commitHash]);

