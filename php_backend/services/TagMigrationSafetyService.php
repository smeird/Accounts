<?php
// Creates immutable classification snapshots and restores them without changing
// transaction identity or financial fields. This is the safety boundary for the
// controlled tag-taxonomy rebuild.
require_once __DIR__ . '/../Database.php';

class TagMigrationSafetyService {
    public const CONTRACT_VERSION = 'v1';

    private $db;

    public function __construct($db = null) {
        $this->db = $db ?: Database::getConnection();
    }

    /** @return array<string,mixed> */
    public static function contract(): array {
        return [
            'version' => self::CONTRACT_VERSION,
            'definitions' => [
                'alias' => 'Stable wording received from a bank that points to one canonical tag.',
                'tag' => 'A controlled, reusable transaction type used for search and reporting.',
                'category' => 'A broader reporting classification implied by a tag.',
                'segment' => 'The highest-level financial grouping implied by a category.',
            ],
            'protected' => [
                'transfer' => 'Confirmed transfers are never eligible for AI retagging.',
                'ignored' => 'Transactions explicitly tagged IGNORE keep that protected state.',
            ],
            'success_thresholds' => [
                'eligible_coverage_percent' => 98,
                'transfer_protection_percent' => 100,
                'financial_reconciliation_percent' => 100,
                'maximum_alias_false_positive_percent' => 2,
                'unreviewed_new_tags' => 0,
                'repeat_run_taxonomy_growth' => 0,
            ],
            'snapshot_fields' => ['tag_id', 'category_id', 'segment_id'],
        ];
    }

    public function schemaReady(): bool {
        try {
            $this->db->query('SELECT `id`, `snapshot_hash` FROM `tag_migration_runs` WHERE 1 = 0');
            $this->db->query('SELECT `run_id`, `transaction_id` FROM `transaction_classification_snapshots` WHERE 1 = 0');
            $this->db->query('SELECT `origin`, `status`, `merged_into_tag_id` FROM `tags` WHERE 1 = 0');
            $this->db->query('SELECT `origin`, `confidence`, `support_count`, `last_matched_at` FROM `tag_aliases` WHERE 1 = 0');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return array<string,int|float> */
    public function currentClassificationPreview(): array {
        $sql = "SELECT COUNT(*) AS transaction_count,
                SUM(CASE WHEN t.transfer_id IS NOT NULL THEN 1 ELSE 0 END) AS protected_transfer_count,
                SUM(CASE WHEN t.transfer_id IS NULL AND UPPER(TRIM(COALESCE(tg.name, ''))) = 'IGNORE' THEN 1 ELSE 0 END) AS protected_ignore_count,
                SUM(CASE WHEN t.transfer_id IS NULL AND UPPER(TRIM(COALESCE(tg.name, ''))) <> 'IGNORE' THEN 1 ELSE 0 END) AS eligible_count,
                SUM(CASE WHEN t.transfer_id IS NULL AND UPPER(TRIM(COALESCE(tg.name, ''))) <> 'IGNORE' AND t.tag_id IS NOT NULL THEN 1 ELSE 0 END) AS eligible_tagged_count,
                SUM(CASE WHEN t.transfer_id IS NULL AND UPPER(TRIM(COALESCE(tg.name, ''))) <> 'IGNORE' AND t.tag_id IS NULL THEN 1 ELSE 0 END) AS eligible_untagged_count
            FROM transactions t
            LEFT JOIN tags tg ON tg.id = t.tag_id";
        $row = $this->db->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];
        $eligible = (int)($row['eligible_count'] ?? 0);
        $tagged = (int)($row['eligible_tagged_count'] ?? 0);
        return [
            'transaction_count' => (int)($row['transaction_count'] ?? 0),
            'eligible_count' => $eligible,
            'protected_transfer_count' => (int)($row['protected_transfer_count'] ?? 0),
            'protected_ignore_count' => (int)($row['protected_ignore_count'] ?? 0),
            'eligible_tagged_count' => $tagged,
            'eligible_untagged_count' => (int)($row['eligible_untagged_count'] ?? 0),
            'eligible_tagged_percent' => $eligible > 0 ? round(($tagged / $eligible) * 100, 1) : 100.0,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function listRuns(int $limit = 20): array {
        $this->requireSchema();
        $limit = max(1, min(100, $limit));
        $rows = $this->db->query(
            'SELECT r.*, COUNT(s.transaction_id) AS snapshot_rows '
            . 'FROM tag_migration_runs r '
            . 'LEFT JOIN transaction_classification_snapshots s ON s.run_id = r.id '
            . 'GROUP BY r.id ORDER BY r.id DESC LIMIT ' . $limit
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map([$this, 'normaliseRun'], $rows);
    }

    /** @return array<string,mixed> */
    public function createSnapshot(string $name, ?string $createdBy = null): array {
        $this->requireSchema();
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if ($name === '') {
            $name = 'Tag rebuild baseline ' . gmdate('Y-m-d H:i');
        }
        if (strlen($name) > 150) {
            throw new InvalidArgumentException('Snapshot names must be 150 characters or fewer.');
        }
        $createdBy = $createdBy === null ? null : substr(trim($createdBy), 0, 100);

        $this->db->beginTransaction();
        try {
            $insertRun = $this->db->prepare(
                'INSERT INTO tag_migration_runs '
                . '(name, status, contract_version, created_by, snapshot_hash) '
                . "VALUES (:name, 'snapshot', :contract_version, :created_by, '')"
            );
            $insertRun->execute([
                'name' => $name,
                'contract_version' => self::CONTRACT_VERSION,
                'created_by' => $createdBy,
            ]);
            $runId = (int)$this->db->lastInsertId();

            $snapshotSql = "INSERT INTO transaction_classification_snapshots
                (run_id, transaction_id, tag_id, category_id, segment_id, eligible, protection_reason)
                SELECT :run_id, t.id, t.tag_id, t.category_id, t.segment_id,
                    CASE WHEN t.transfer_id IS NULL AND UPPER(TRIM(COALESCE(tg.name, ''))) <> 'IGNORE' THEN 1 ELSE 0 END,
                    CASE
                        WHEN t.transfer_id IS NOT NULL THEN 'transfer'
                        WHEN UPPER(TRIM(COALESCE(tg.name, ''))) = 'IGNORE' THEN 'ignored'
                        ELSE NULL
                    END
                FROM transactions t
                LEFT JOIN tags tg ON tg.id = t.tag_id
                ORDER BY t.id";
            $insertSnapshot = $this->db->prepare($snapshotSql);
            $insertSnapshot->execute(['run_id' => $runId]);

            $counts = $this->snapshotCounts($runId);
            $hash = $this->calculateSnapshotHash($runId);
            $updateRun = $this->db->prepare(
                'UPDATE tag_migration_runs SET transaction_count = :transactions, eligible_count = :eligible, '
                . 'protected_transfer_count = :transfers, protected_ignore_count = :ignored, snapshot_hash = :snapshot_hash '
                . 'WHERE id = :id'
            );
            $updateRun->execute([
                'transactions' => $counts['transaction_count'],
                'eligible' => $counts['eligible_count'],
                'transfers' => $counts['protected_transfer_count'],
                'ignored' => $counts['protected_ignore_count'],
                'snapshot_hash' => $hash,
                'id' => $runId,
            ]);
            $this->db->commit();
            return $this->getRun($runId);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function rollbackPreview(int $runId): array {
        $this->requireSchema();
        $run = $this->getRun($runId);
        $calculatedHash = $this->calculateSnapshotHash($runId);
        $hashValid = hash_equals((string)$run['snapshot_hash'], $calculatedHash);
        $stmt = $this->db->prepare(
            'SELECT s.transaction_id, s.tag_id, s.category_id, s.segment_id, s.eligible, s.protection_reason, '
            . 't.id AS current_id, t.tag_id AS current_tag_id, t.category_id AS current_category_id, t.segment_id AS current_segment_id '
            . 'FROM transaction_classification_snapshots s '
            . 'LEFT JOIN transactions t ON t.id = s.transaction_id '
            . 'WHERE s.run_id = :run_id ORDER BY s.transaction_id'
        );
        $stmt->execute(['run_id' => $runId]);

        $changed = 0;
        $protectedChanged = 0;
        $missingTransactions = 0;
        $referenceIds = ['tags' => [], 'categories' => [], 'segments' => []];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['current_id'] === null) {
                $missingTransactions++;
            } elseif (!$this->sameNullableId($row['tag_id'], $row['current_tag_id'])
                || !$this->sameNullableId($row['category_id'], $row['current_category_id'])
                || !$this->sameNullableId($row['segment_id'], $row['current_segment_id'])) {
                $changed++;
                if ((int)$row['eligible'] === 0) {
                    $protectedChanged++;
                }
            }
            if ($row['tag_id'] !== null) $referenceIds['tags'][(int)$row['tag_id']] = true;
            if ($row['category_id'] !== null) $referenceIds['categories'][(int)$row['category_id']] = true;
            if ($row['segment_id'] !== null) $referenceIds['segments'][(int)$row['segment_id']] = true;
        }

        $missingReferences = [];
        foreach ($referenceIds as $table => $ids) {
            $missing = $this->missingIds($table, array_keys($ids));
            if ($missing) {
                $missingReferences[$table] = $missing;
            }
        }
        $newStmt = $this->db->prepare(
            'SELECT COUNT(*) FROM transactions t LEFT JOIN transaction_classification_snapshots s '
            . 'ON s.run_id = :run_id AND s.transaction_id = t.id WHERE s.transaction_id IS NULL'
        );
        $newStmt->execute(['run_id' => $runId]);
        $newTransactions = (int)$newStmt->fetchColumn();
        $blockers = [];
        if (!$hashValid) $blockers[] = 'The stored snapshot no longer matches its integrity hash.';
        if ($missingTransactions > 0) $blockers[] = $missingTransactions . ' snapshotted transaction(s) no longer exist.';
        if ($missingReferences) $blockers[] = 'One or more original classifications no longer exist.';

        return [
            'run' => $run,
            'hash_valid' => $hashValid,
            'changed_transactions' => $changed,
            'protected_changes' => $protectedChanged,
            'missing_transactions' => $missingTransactions,
            'new_transactions_untouched' => $newTransactions,
            'missing_references' => $missingReferences,
            'blockers' => $blockers,
            'restorable' => !$blockers,
        ];
    }

    /** @return array<string,mixed> */
    public function restoreSnapshot(int $runId): array {
        $preview = $this->rollbackPreview($runId);
        if (!$preview['restorable']) {
            throw new RuntimeException('The snapshot cannot be restored until its integrity blockers are resolved.');
        }

        $this->db->beginTransaction();
        try {
            $driver = (string)$this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'mysql') {
                $stmt = $this->db->prepare(
                    'UPDATE transactions t INNER JOIN transaction_classification_snapshots s ON s.transaction_id = t.id '
                    . 'SET t.tag_id = s.tag_id, t.category_id = s.category_id, t.segment_id = s.segment_id '
                    . 'WHERE s.run_id = :run_id'
                );
            } else {
                $stmt = $this->db->prepare(
                    'UPDATE transactions SET '
                    . 'tag_id = (SELECT s.tag_id FROM transaction_classification_snapshots s WHERE s.run_id = :tag_run AND s.transaction_id = transactions.id), '
                    . 'category_id = (SELECT s.category_id FROM transaction_classification_snapshots s WHERE s.run_id = :category_run AND s.transaction_id = transactions.id), '
                    . 'segment_id = (SELECT s.segment_id FROM transaction_classification_snapshots s WHERE s.run_id = :segment_run AND s.transaction_id = transactions.id) '
                    . 'WHERE EXISTS (SELECT 1 FROM transaction_classification_snapshots s WHERE s.run_id = :exists_run AND s.transaction_id = transactions.id)'
                );
            }
            $params = $driver === 'mysql'
                ? ['run_id' => $runId]
                : ['tag_run' => $runId, 'category_run' => $runId, 'segment_run' => $runId, 'exists_run' => $runId];
            $stmt->execute($params);

            $updateRun = $this->db->prepare(
                "UPDATE tag_migration_runs SET status = 'rolled_back', rolled_back_at = CURRENT_TIMESTAMP WHERE id = :id"
            );
            $updateRun->execute(['id' => $runId]);

            $after = $this->rollbackPreview($runId);
            if (!$after['hash_valid'] || $after['changed_transactions'] !== 0) {
                throw new RuntimeException('Snapshot verification failed after restoring classifications.');
            }
            $this->db->commit();
            return [
                'status' => 'restored',
                'run' => $this->getRun($runId),
                'restored_transactions' => (int)$preview['changed_transactions'],
                'new_transactions_untouched' => (int)$preview['new_transactions_untouched'],
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function getRun(int $runId): array {
        if ($runId <= 0) {
            throw new InvalidArgumentException('A valid migration snapshot is required.');
        }
        $stmt = $this->db->prepare(
            'SELECT r.*, COUNT(s.transaction_id) AS snapshot_rows '
            . 'FROM tag_migration_runs r '
            . 'LEFT JOIN transaction_classification_snapshots s ON s.run_id = r.id '
            . 'WHERE r.id = :id GROUP BY r.id'
        );
        $stmt->execute(['id' => $runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new InvalidArgumentException('Migration snapshot not found.');
        }
        return $this->normaliseRun($row);
    }

    /** @return array<string,int> */
    private function snapshotCounts(int $runId): array {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS transaction_count,
                SUM(CASE WHEN eligible = 1 THEN 1 ELSE 0 END) AS eligible_count,
                SUM(CASE WHEN protection_reason = 'transfer' THEN 1 ELSE 0 END) AS protected_transfer_count,
                SUM(CASE WHEN protection_reason = 'ignored' THEN 1 ELSE 0 END) AS protected_ignore_count
            FROM transaction_classification_snapshots WHERE run_id = :run_id"
        );
        $stmt->execute(['run_id' => $runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'transaction_count' => (int)($row['transaction_count'] ?? 0),
            'eligible_count' => (int)($row['eligible_count'] ?? 0),
            'protected_transfer_count' => (int)($row['protected_transfer_count'] ?? 0),
            'protected_ignore_count' => (int)($row['protected_ignore_count'] ?? 0),
        ];
    }

    private function calculateSnapshotHash(int $runId): string {
        $stmt = $this->db->prepare(
            'SELECT transaction_id, tag_id, category_id, segment_id, eligible, protection_reason '
            . 'FROM transaction_classification_snapshots WHERE run_id = :run_id ORDER BY transaction_id'
        );
        $stmt->execute(['run_id' => $runId]);
        $context = hash_init('sha256');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $parts = [];
            foreach (['transaction_id', 'tag_id', 'category_id', 'segment_id', 'eligible', 'protection_reason'] as $field) {
                $parts[] = $row[$field] === null ? '~' : (string)$row[$field];
            }
            hash_update($context, implode('|', $parts) . "\n");
        }
        return hash_final($context);
    }

    /** @return array<int,int> */
    private function missingIds(string $table, array $ids): array {
        if (!$ids) return [];
        if (!in_array($table, ['tags', 'categories', 'segments'], true)) {
            throw new InvalidArgumentException('Unsupported classification reference.');
        }
        $found = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->prepare("SELECT id FROM {$table} WHERE id IN ({$marks})");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $found[(int)$id] = true;
            }
        }
        $missing = array_values(array_filter(array_map('intval', $ids), function($id) use ($found) {
            return !isset($found[$id]);
        }));
        sort($missing);
        return $missing;
    }

    private function sameNullableId($left, $right): bool {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }
        return (int)$left === (int)$right;
    }

    /** @return array<string,mixed> */
    private function normaliseRun(array $row): array {
        foreach (['id', 'transaction_count', 'eligible_count', 'protected_transfer_count', 'protected_ignore_count', 'snapshot_rows'] as $field) {
            $row[$field] = (int)($row[$field] ?? 0);
        }
        return $row;
    }

    private function requireSchema(): void {
        if (!$this->schemaReady()) {
            throw new RuntimeException('Phase 1 database structures are missing. Run Database Health and apply the catalogue repairs first.');
        }
    }
}

