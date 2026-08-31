<?php

/**
 * Read and safely fast-forward the deployed Git checkout.
 *
 * The browser never supplies a repository, remote, branch, or command. The
 * service operates only on the application checkout and refuses dirty,
 * detached, diverged, or locally-ahead states.
 */
class ApplicationUpdateService {
    private const PRIVILEGED_HELPER = '/usr/local/sbin/accounts-application-git';
    private $repository;
    private $runner;

    public function __construct(string $repository, $runner = null) {
        if ($runner !== null && !is_callable($runner)) {
            throw new InvalidArgumentException('Application update runner must be callable.');
        }
        $resolved = realpath($repository);
        $this->repository = $resolved !== false ? $resolved : $repository;
        $this->runner = $runner;
    }

    public function status(bool $refresh = true): array {
        $checkedAt = gmdate('c');
        if (!is_dir($this->repository . '/.git')) {
            return $this->unavailable('This installation is not a Git checkout.', $checkedAt);
        }
        $gitVersion = $this->run(['--version']);
        if (!$gitVersion['ok']) {
            return $this->unavailable('Git is not available to the web application.', $checkedAt, $gitVersion['output']);
        }
        $inside = $this->run(['rev-parse', '--is-inside-work-tree']);
        if (!$inside['ok'] || trim($inside['output']) !== 'true') {
            return $this->unavailable('The application directory is not a readable Git checkout.', $checkedAt, $inside['output']);
        }
        $branchResult = $this->run(['symbolic-ref', '--quiet', '--short', 'HEAD']);
        if (!$branchResult['ok'] || trim($branchResult['output']) === '') {
            return $this->blocked('The checkout is using a detached commit. Select a branch on the server before using in-app updates.', $checkedAt);
        }
        $branch = trim($branchResult['output']);
        $statusResult = $this->run(['status', '--porcelain', '--untracked-files=normal']);
        if (!$statusResult['ok']) {
            return $this->unavailable('Git could not inspect the working tree.', $checkedAt, $statusResult['output']);
        }
        $dirty = trim($statusResult['output']) !== '';
        $remoteResult = $this->run(['remote', 'get-url', 'origin']);
        if (!$remoteResult['ok'] || trim($remoteResult['output']) === '') {
            return $this->blocked('No origin remote is configured for this checkout.', $checkedAt, ['branch' => $branch, 'dirty' => $dirty]);
        }

        $fetchError = '';
        if ($refresh) {
            $fetch = $this->run(['fetch', '--prune', 'origin', $branch]);
            if (!$fetch['ok']) $fetchError = $fetch['output'];
        }
        $remoteRef = 'refs/remotes/origin/' . $branch;
        $remoteCommitResult = $this->run(['rev-parse', '--verify', $remoteRef]);
        if (!$remoteCommitResult['ok']) {
            $message = $fetchError !== ''
                ? 'The latest code could not be checked. The web server may not have access to the Git remote.'
                : 'The matching origin branch is not available.';
            return $this->blocked($message, $checkedAt, [
                'branch' => $branch,
                'dirty' => $dirty,
                'detail' => $fetchError !== '' ? $fetchError : $remoteCommitResult['output'],
            ]);
        }
        $counts = $this->run(['rev-list', '--left-right', '--count', 'HEAD...' . $remoteRef]);
        if (!$counts['ok'] || !preg_match('/^(\d+)\s+(\d+)$/', trim($counts['output']), $matches)) {
            return $this->unavailable('Git could not compare the installed and available versions.', $checkedAt, $counts['output']);
        }

        $ahead = (int)$matches[1];
        $behind = (int)$matches[2];
        $commit = $this->run(['rev-parse', '--short=7', 'HEAD']);
        $remoteCommit = $this->run(['rev-parse', '--short=7', $remoteRef]);
        $commitDate = $this->run(['log', '-1', '--format=%cI', 'HEAD']);
        $state = 'current';
        $message = 'This installation is up to date.';
        $canUpdate = false;
        if ($fetchError !== '') {
            $state = 'check_failed';
            $message = 'The remote could not be refreshed, so update availability is unknown.';
        } elseif ($dirty) {
            $state = 'blocked';
            $message = 'Local file changes must be resolved before the application can update safely.';
        } elseif ($ahead > 0 && $behind > 0) {
            $state = 'diverged';
            $message = 'The deployed branch has diverged from origin and needs manual review.';
        } elseif ($ahead > 0) {
            $state = 'ahead';
            $message = 'This installation contains local commits that are not on origin.';
        } elseif ($behind > 0) {
            $state = 'update_available';
            $message = $behind . ' update' . ($behind === 1 ? ' is' : 's are') . ' ready to install.';
            $canUpdate = true;
        }
        return [
            'status' => 'success', 'state' => $state, 'message' => $message,
            'checked_at' => $checkedAt, 'branch' => $branch,
            'commit' => trim($commit['output']), 'commit_date' => trim($commitDate['output']),
            'available_commit' => trim($remoteCommit['output']), 'ahead' => $ahead,
            'behind' => $behind, 'dirty' => $dirty, 'can_update' => $canUpdate,
            'remote_ref' => 'origin/' . $branch, 'detail' => $fetchError,
        ];
    }

    public function update(): array {
        $lock = @fopen(sys_get_temp_dir() . '/accounts-application-update-' . sha1($this->repository) . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            return ['status' => 'error', 'message' => 'Another application update is already running.'];
        }
        try {
            $before = $this->status(true);
            if (empty($before['can_update'])) {
                return ['status' => 'error', 'message' => $before['message'] ?? 'This installation cannot be updated safely.', 'audit' => $before];
            }
            $remoteRef = 'refs/remotes/origin/' . (string)$before['branch'];
            $oldCommit = (string)$before['commit'];
            $merge = $this->run(['merge', '--ff-only', $remoteRef]);
            if (!$merge['ok']) {
                return [
                    'status' => 'error',
                    'message' => 'The update could not be installed. No non-fast-forward merge was attempted.',
                    'detail' => $merge['output'], 'audit' => $this->status(false),
                ];
            }
            $after = $this->status(false);
            $newCommit = (string)($after['commit'] ?? '');
            $changed = $this->run(['diff', '--name-only', $oldCommit . '..' . $newCommit]);
            $changedFiles = array_values(array_filter(preg_split('/\r?\n/', trim($changed['output']))));
            return [
                'status' => 'success', 'message' => 'Application updated successfully.',
                'from' => $oldCommit, 'to' => $newCommit,
                'changed_files' => count($changedFiles), 'audit' => $after,
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function unavailable(string $message, string $checkedAt, string $detail = ''): array {
        return ['status' => 'error', 'state' => 'unavailable', 'message' => $message, 'checked_at' => $checkedAt, 'can_update' => false, 'detail' => trim($detail)];
    }

    private function blocked(string $message, string $checkedAt, array $extra = []): array {
        return array_merge(['status' => 'success', 'state' => 'blocked', 'message' => $message, 'checked_at' => $checkedAt, 'can_update' => false], $extra);
    }

    private function run(array $arguments): array {
        if ($this->runner !== null) return call_user_func($this->runner, $arguments);
        $parts = array_map('escapeshellarg', $arguments);
        if (is_executable(self::PRIVILEGED_HELPER)) {
            $command = 'sudo -n ' . escapeshellarg(self::PRIVILEGED_HELPER) . ' ' . implode(' ', $parts);
        } else {
            $command = 'GIT_TERMINAL_PROMPT=0 GIT_SSH_COMMAND=' . escapeshellarg('ssh -o BatchMode=yes')
                . ' git -c safe.directory=' . escapeshellarg($this->repository)
                . ' -C ' . escapeshellarg($this->repository) . ' ' . implode(' ', $parts);
        }
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($command, $descriptors, $pipes, $this->repository);
        if (!is_resource($process)) return ['ok' => false, 'code' => 127, 'output' => 'Unable to start Git.'];
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $started = microtime(true);
        $timedOut = false;
        $observedCode = null;
        do {
            $output .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            $processStatus = proc_get_status($process);
            if (!$processStatus['running']) {
                $observedCode = isset($processStatus['exitcode']) ? (int)$processStatus['exitcode'] : null;
                break;
            }
            if (microtime(true) - $started > 30) {
                $timedOut = true;
                proc_terminate($process);
                break;
            }
            usleep(50000);
        } while (true);
        $output .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code === -1 && $observedCode !== null) $code = $observedCode;
        if ($timedOut) return ['ok' => false, 'code' => 124, 'output' => 'Git operation timed out after 30 seconds.'];
        return ['ok' => $code === 0, 'code' => $code, 'output' => trim(substr($output, 0, 8000))];
    }
}
