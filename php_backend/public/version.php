<?php
// Outputs the current git commit hash for version display without relying on shell_exec.
header('Content-Type: application/json');
$rootDir = dirname(__DIR__, 2);
$commitHash = '';
$headPath = $rootDir . '/.git/HEAD';
if (is_readable($headPath)) {
    $ref = trim(file_get_contents($headPath));
    if (strpos($ref, 'ref: ') === 0) {
        $refPath = $rootDir . '/.git/' . substr($ref, 5);
        if (is_readable($refPath)) {
            $commitHash = trim(file_get_contents($refPath));
        }
    } else {
        $commitHash = $ref;
    }
}
$commitHash = $commitHash ? substr($commitHash, 0, 7) : null;
echo json_encode(['version' => $commitHash]);
