<?php
// Outputs the current git commit hash for version display without relying on shell_exec.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/Log.php';
header('Content-Type: application/json');
$rootDir = dirname(__DIR__, 2);

// Determine the current commit hash and branch name.
$commitHash = '';
$branch = null;
$headPath = $rootDir . '/.git/HEAD';
if (is_readable($headPath)) {
    $ref = trim(file_get_contents($headPath));
    if (strpos($ref, 'ref: ') === 0) {
        $branchRef = substr($ref, 5);
        $branch = basename($branchRef);
        $refPath = $rootDir . '/.git/' . $branchRef;
        if (is_readable($refPath)) {
            $commitHash = trim(file_get_contents($refPath));
        }
    } else {
        $commitHash = $ref;
    }
} else {
    Log::write('Version check failed: HEAD not readable', 'ERROR');
}

// Attempt to determine how many commits behind the remote the current branch is.
$behind = null;
if ($branch) {
    // Fetch latest refs but ignore errors if git or the remote are unavailable.
    @exec(sprintf('git -C %s fetch origin 2>&1', escapeshellarg($rootDir)));
    if (preg_match('/^[A-Za-z0-9._\/-]+$/', $branch)) {
        $cmd = sprintf('git -C %s rev-list --count HEAD..origin/%s 2>&1', escapeshellarg($rootDir), $branch);
        $output = [];
        $exitCode = null;
        @exec($cmd, $output, $exitCode);
        if ($exitCode === 0 && isset($output[0]) && ctype_digit($output[0])) {
            $behind = (int)$output[0];
        }
    }
}

$commitHash = $commitHash ? substr($commitHash, 0, 7) : null;
echo json_encode(['version' => $commitHash, 'behind' => $behind]);
