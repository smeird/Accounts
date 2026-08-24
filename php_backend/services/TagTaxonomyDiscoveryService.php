<?php
// Phase 2 review-only taxonomy discovery. Every write is confined to staging
// tables; live tags, aliases and transaction classifications are never updated.
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../TagTaxonomyPattern.php';
require_once __DIR__ . '/../models/Tag.php';
require_once __DIR__ . '/TagMigrationSafetyService.php';

class TagTaxonomyDiscoveryService {
    const EARLY_FINISH_MIN_COVERAGE = 95.0;

    private $db;

    public function __construct($db = null) {
        $this->db = $db ?: Database::getConnection();
    }

    public function schemaReady(): bool {
        try {
            $this->db->query('SELECT `discovery_started_at`, `ready_at` FROM `tag_migration_runs` WHERE 1 = 0');
            $this->db->query('SELECT `id`, `canonical_name`, `status` FROM `tag_taxonomy_proposals` WHERE 1 = 0');
            $this->db->query('SELECT `id`, `signature`, `proposal_id` FROM `tag_taxonomy_patterns` WHERE 1 = 0');
            $this->db->query('SELECT `run_id`, `transaction_id`, `pattern_id` FROM `transaction_tag_proposals` WHERE 1 = 0');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    public function overview(?int $requestedRunId = null): array {
        $this->requireSchema();
        $runs = $this->db->query(
            "SELECT id, name, status, transaction_count, eligible_count, protected_transfer_count, protected_ignore_count, "
            . "created_at, discovery_started_at, ready_at FROM tag_migration_runs "
            . "WHERE snapshot_hash <> '' ORDER BY id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($runs as &$run) {
            foreach (['id', 'transaction_count', 'eligible_count', 'protected_transfer_count', 'protected_ignore_count'] as $field) {
                $run[$field] = (int)($run[$field] ?? 0);
            }
        }
        unset($run);

        $runId = $requestedRunId && $requestedRunId > 0 ? $requestedRunId : null;
        if ($runId === null) {
            foreach ($runs as $candidate) {
                if (!empty($candidate['discovery_started_at'])) {
                    $runId = (int)$candidate['id'];
                    break;
                }
            }
        }
        if ($runId === null && !empty($runs)) $runId = (int)$runs[0]['id'];
        $selectedRun = null;
        foreach ($runs as $candidate) {
            if ((int)$candidate['id'] === $runId) {
                $selectedRun = $candidate;
                break;
            }
        }
        if ($runId !== null && $selectedRun === null) {
            throw new InvalidArgumentException('The selected classification snapshot does not exist.');
        }

        $categories = $this->db->query('SELECT id, name, description FROM categories ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($categories as &$category) $category['id'] = (int)$category['id'];
        unset($category);
        return [
            'schema_ready' => true,
            'runs' => $runs,
            'selected_run' => $selectedRun,
            'categories' => $categories,
            'metrics' => $runId ? $this->metrics($runId) : $this->emptyMetrics(),
            'proposals' => $runId ? $this->listProposals($runId) : [],
        ];
    }

    /** @return array<string,mixed> */
    public function prepare(int $runId): array {
        $this->requireSchema();
        $run = $this->requireRun($runId);
        if (!in_array($run['status'], ['snapshot', 'staging'], true)) {
            throw new RuntimeException('Only an intact snapshot can enter taxonomy discovery.');
        }
        $existing = $this->countForRun('tag_taxonomy_patterns', $runId);
        if ($existing > 0) return $this->overview($runId);

        $safety = new TagMigrationSafetyService($this->db);
        $preview = $safety->rollbackPreview($runId);
        if (!$preview['restorable']) {
            throw new RuntimeException('The baseline snapshot has integrity blockers and cannot be used for discovery.');
        }

        $stmt = $this->db->prepare(
            'SELECT t.id, t.date, t.amount, t.description, t.memo, s.tag_id AS current_tag_id, tg.name AS current_tag_name '
            . 'FROM transaction_classification_snapshots s '
            . 'INNER JOIN transactions t ON t.id = s.transaction_id '
            . 'LEFT JOIN tags tg ON tg.id = s.tag_id '
            . 'WHERE s.run_id = :run_id AND s.eligible = 1 ORDER BY t.id'
        );
        $stmt->execute(['run_id' => $runId]);
        $groups = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $pattern = TagTaxonomyPattern::fromTransaction((string)$row['description'], $row['memo'], $row['amount']);
            $key = $pattern['signature'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'pattern' => $pattern,
                    'sample_description' => substr((string)$row['description'], 0, 255),
                    'sample_memo' => $row['memo'] === null ? null : substr((string)$row['memo'], 0, 255),
                    'transaction_count' => 0,
                    'absolute_amount' => 0.0,
                    'first_seen' => (string)$row['date'],
                    'last_seen' => (string)$row['date'],
                    'current_tags' => [],
                ];
            }
            $group =& $groups[$key];
            $group['transaction_count']++;
            $group['absolute_amount'] += abs((float)$row['amount']);
            if ((string)$row['date'] < $group['first_seen']) $group['first_seen'] = (string)$row['date'];
            if ((string)$row['date'] > $group['last_seen']) $group['last_seen'] = (string)$row['date'];
            $tagLabel = $row['current_tag_name'] !== null ? (string)$row['current_tag_name'] : 'Unassigned';
            $group['current_tags'][$tagLabel] = ($group['current_tags'][$tagLabel] ?? 0) + 1;
            unset($group);
        }
        if (count($groups) === 0 && (int)$run['eligible_count'] > 0) {
            throw new RuntimeException('No eligible transaction patterns could be prepared from the snapshot.');
        }

        $this->db->beginTransaction();
        try {
            $insertPattern = $this->db->prepare(
                'INSERT INTO tag_taxonomy_patterns '
                . '(run_id, signature, alias, alias_normalized, direction, sample_description, sample_memo, current_tags, transaction_count, absolute_amount, first_seen, last_seen) '
                . 'VALUES (:run_id, :signature, :alias, :alias_normalized, :direction, :sample_description, :sample_memo, :current_tags, :transaction_count, :absolute_amount, :first_seen, :last_seen)'
            );
            $patternIds = [];
            foreach ($groups as $group) {
                arsort($group['current_tags']);
                $insertPattern->execute([
                    'run_id' => $runId,
                    'signature' => $group['pattern']['signature'],
                    'alias' => $group['pattern']['alias'],
                    'alias_normalized' => $group['pattern']['alias_normalized'],
                    'direction' => $group['pattern']['direction'],
                    'sample_description' => $group['sample_description'],
                    'sample_memo' => $group['sample_memo'],
                    'current_tags' => json_encode($group['current_tags'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'transaction_count' => $group['transaction_count'],
                    'absolute_amount' => round($group['absolute_amount'], 2),
                    'first_seen' => $group['first_seen'],
                    'last_seen' => $group['last_seen'],
                ]);
                $patternId = (int)$this->db->lastInsertId();
                $patternIds[$group['pattern']['signature']] = $patternId;
            }
            $transactionBatch = [];
            $stmt->execute(['run_id' => $runId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $pattern = TagTaxonomyPattern::fromTransaction((string)$row['description'], $row['memo'], $row['amount']);
                $patternId = $patternIds[$pattern['signature']] ?? null;
                if ($patternId === null) {
                    throw new RuntimeException('A prepared transaction could not be linked to its deterministic pattern.');
                }
                $transactionBatch[] = [$runId, (int)$row['id'], $patternId, $row['current_tag_id'] === null ? null : (int)$row['current_tag_id']];
                if (count($transactionBatch) >= 500) {
                    $this->insertTransactionBatch($transactionBatch);
                    $transactionBatch = [];
                }
            }
            if (!empty($transactionBatch)) $this->insertTransactionBatch($transactionBatch);
            $update = $this->db->prepare(
                "UPDATE tag_migration_runs SET status = 'staging', discovery_started_at = CURRENT_TIMESTAMP, ready_at = NULL WHERE id = :id"
            );
            $update->execute(['id' => $runId]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return $this->overview($runId);
    }

    /** @return array<int,array<string,mixed>> */
    public function pendingPatterns(int $runId, int $limit = 30): array {
        $this->requireStagingRun($runId);
        $limit = max(1, min(60, $limit));
        $stmt = $this->db->prepare(
            "SELECT id, alias, direction, sample_description, sample_memo, current_tags, transaction_count, absolute_amount, first_seen, last_seen "
            . "FROM tag_taxonomy_patterns WHERE run_id = :run_id AND status = 'pending' "
            . 'ORDER BY transaction_count DESC, absolute_amount DESC, id ASC LIMIT ' . $limit
        );
        $stmt->execute(['run_id' => $runId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['transaction_count'] = (int)$row['transaction_count'];
            $row['absolute_amount'] = (float)$row['absolute_amount'];
            $current = json_decode((string)$row['current_tags'], true);
            $row['current_tags'] = is_array($current) ? $this->summariseCurrentTags($current) : 'Unassigned';
        }
        unset($row);
        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    public function stagedCanonicalContext(int $runId): array {
        $stmt = $this->db->prepare(
            "SELECT canonical_name, description FROM tag_taxonomy_proposals WHERE run_id = :run_id AND status <> 'rejected' ORDER BY transaction_count DESC, canonical_name"
        );
        $stmt->execute(['run_id' => $runId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,string> */
    public function rejectedNames(int $runId): array {
        $stmt = $this->db->prepare("SELECT canonical_name FROM tag_taxonomy_proposals WHERE run_id = :run_id AND status = 'rejected' ORDER BY id");
        $stmt->execute(['run_id' => $runId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<string,mixed> */
    public function applyAiAssignments(int $runId, array $assignments): array {
        $this->requireStagingRun($runId);
        if (empty($assignments)) return $this->overview($runId);
        $this->db->beginTransaction();
        try {
            $this->requireLockedStagingRun($runId);
            foreach ($assignments as $assignment) {
                $pattern = $this->requirePendingPattern($runId, (int)$assignment['pattern_id']);
                $proposalId = $this->findOrCreateProposal($runId, $assignment);
                $updatePattern = $this->db->prepare(
                    "UPDATE tag_taxonomy_patterns SET proposal_id = :proposal_id, confidence = :confidence, rationale = :rationale, status = 'proposed' "
                    . "WHERE id = :id AND run_id = :run_id AND status = 'pending'"
                );
                $updatePattern->execute([
                    'proposal_id' => $proposalId,
                    'confidence' => $assignment['confidence'],
                    'rationale' => $assignment['rationale'],
                    'id' => $pattern['id'],
                    'run_id' => $runId,
                ]);
                if ($updatePattern->rowCount() !== 1) {
                    throw new RuntimeException('A taxonomy pattern changed while the AI batch was running. Refresh before continuing.');
                }
                $updateTransactions = $this->db->prepare(
                    'UPDATE transaction_tag_proposals SET proposal_id = :proposal_id, confidence = :confidence WHERE run_id = :run_id AND pattern_id = :pattern_id'
                );
                $updateTransactions->execute([
                    'proposal_id' => $proposalId,
                    'confidence' => $assignment['confidence'],
                    'run_id' => $runId,
                    'pattern_id' => $pattern['id'],
                ]);
            }
            $this->refreshProposalMetrics($runId);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return $this->overview($runId);
    }

    /** @return array<string,mixed> */
    public function reviewProposal(int $runId, int $proposalId, array $values, string $reviewedBy): array {
        $this->requireStagingRun($runId);
        $proposal = $this->requireProposal($runId, $proposalId);
        $status = (string)($values['status'] ?? 'pending');
        if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
            throw new InvalidArgumentException('Choose pending, approved, or rejected.');
        }
        $name = trim((string)($values['canonical_name'] ?? $proposal['canonical_name']));
        $normalized = TagTaxonomyPattern::normalize($name);
        if ($normalized === '' || $normalized === 'ignore' || strlen($name) > 100) {
            throw new InvalidArgumentException('Enter a reusable canonical tag name of 100 characters or fewer.');
        }
        $categoryId = isset($values['category_id']) && $values['category_id'] !== '' && $values['category_id'] !== null
            ? (int)$values['category_id'] : null;
        if ($categoryId !== null && !$this->categoryExists($categoryId)) {
            throw new InvalidArgumentException('Choose an existing category.');
        }
        $duplicate = $this->db->prepare(
            'SELECT id FROM tag_taxonomy_proposals WHERE run_id = :run_id AND canonical_name_normalized = :name AND id <> :id LIMIT 1'
        );
        $duplicate->execute(['run_id' => $runId, 'name' => $normalized, 'id' => $proposalId]);
        if ($duplicate->fetchColumn() !== false) {
            throw new InvalidArgumentException('That canonical name already exists in this staged taxonomy.');
        }

        $this->db->beginTransaction();
        try {
            $this->requireLockedStagingRun($runId);
            $update = $this->db->prepare(
                'UPDATE tag_taxonomy_proposals SET canonical_name = :name, canonical_name_normalized = :normalized, '
                . 'description = :description, category_id = :category_id, status = :status, origin = :origin, '
                . 'reviewed_by = :reviewed_by, reviewed_at = CURRENT_TIMESTAMP WHERE id = :id AND run_id = :run_id'
            );
            $update->execute([
                'name' => $name,
                'normalized' => $normalized,
                'description' => substr(trim((string)($values['description'] ?? $proposal['description'])), 0, 1000),
                'category_id' => $categoryId,
                'status' => $status,
                'origin' => (($name !== $proposal['canonical_name']) || array_key_exists('description', $values) || array_key_exists('category_id', $values)) ? 'manual' : $proposal['origin'],
                'reviewed_by' => substr($reviewedBy, 0, 100),
                'id' => $proposalId,
                'run_id' => $runId,
            ]);
            if ($status === 'rejected') {
                $patterns = $this->db->prepare(
                    "UPDATE tag_taxonomy_patterns SET proposal_id = NULL, confidence = NULL, status = 'pending' WHERE run_id = :run_id AND proposal_id = :proposal_id"
                );
                $patterns->execute(['run_id' => $runId, 'proposal_id' => $proposalId]);
                $transactions = $this->db->prepare(
                    'UPDATE transaction_tag_proposals SET proposal_id = NULL, confidence = NULL WHERE run_id = :run_id AND proposal_id = :proposal_id'
                );
                $transactions->execute(['run_id' => $runId, 'proposal_id' => $proposalId]);
            }
            $this->refreshProposalMetrics($runId);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return $this->overview($runId);
    }

    /** @return array<string,mixed> */
    public function markReady(int $runId, bool $deferRemaining = false): array {
        $this->db->beginTransaction();
        try {
            $this->requireLockedStagingRun($runId);
            $metrics = $this->metrics($runId);
            if ($metrics['pending_proposals'] > 0 || $metrics['approved_proposals'] === 0) {
                throw new RuntimeException('Approve every active canonical proposal before marking the taxonomy ready.');
            }
            if ($metrics['pending_patterns'] > 0 && !$deferRemaining) {
                throw new RuntimeException('Analyse every pattern, or explicitly defer the remainder after reaching 95% transaction coverage.');
            }
            if ($metrics['pending_patterns'] > 0 && $metrics['coverage_percent'] < self::EARLY_FINISH_MIN_COVERAGE) {
                throw new RuntimeException('At least 95% transaction coverage is required before unresolved patterns can be deferred.');
            }
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM tag_taxonomy_patterns p LEFT JOIN tag_taxonomy_proposals d ON d.id = p.proposal_id "
                . "WHERE p.run_id = :run_id AND p.status = 'proposed' AND (p.proposal_id IS NULL OR d.status <> 'approved')"
            );
            $stmt->execute(['run_id' => $runId]);
            if ((int)$stmt->fetchColumn() > 0) {
                throw new RuntimeException('Every analysed pattern must resolve to an approved canonical tag.');
            }
            if ($deferRemaining && $metrics['pending_patterns'] > 0) {
                $defer = $this->db->prepare(
                    "UPDATE tag_taxonomy_patterns SET status = 'excluded', "
                    . "rationale = 'Deferred when taxonomy was finalised at or above 95% transaction coverage' "
                    . "WHERE run_id = :run_id AND status = 'pending'"
                );
                $defer->execute(['run_id' => $runId]);
            }
            $update = $this->db->prepare("UPDATE tag_migration_runs SET status = 'ready', ready_at = CURRENT_TIMESTAMP WHERE id = :id");
            $update->execute(['id' => $runId]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return $this->overview($runId);
    }

    /** @return array<string,int|float> */
    private function metrics(int $runId): array {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS patterns, "
            . "SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_patterns, "
            . "SUM(CASE WHEN status = 'proposed' THEN 1 ELSE 0 END) AS proposed_patterns, "
            . "SUM(CASE WHEN status = 'excluded' THEN 1 ELSE 0 END) AS deferred_patterns, "
            . "COALESCE(SUM(transaction_count),0) AS transactions, "
            . "COALESCE(SUM(CASE WHEN status = 'proposed' THEN transaction_count ELSE 0 END),0) AS proposed_transactions, "
            . "COALESCE(SUM(CASE WHEN status = 'excluded' THEN transaction_count ELSE 0 END),0) AS deferred_transactions "
            . 'FROM tag_taxonomy_patterns WHERE run_id = :run_id'
        );
        $stmt->execute(['run_id' => $runId]);
        $patterns = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $proposalStmt = $this->db->prepare(
            "SELECT SUM(CASE WHEN status <> 'rejected' THEN 1 ELSE 0 END) AS proposals, "
            . "SUM(CASE WHEN status = 'pending' AND pattern_count > 0 THEN 1 ELSE 0 END) AS pending_proposals, "
            . "SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_proposals, "
            . "SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_proposals "
            . 'FROM tag_taxonomy_proposals WHERE run_id = :run_id'
        );
        $proposalStmt->execute(['run_id' => $runId]);
        $proposals = $proposalStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $transactions = (int)($patterns['transactions'] ?? 0);
        $proposedTransactions = (int)($patterns['proposed_transactions'] ?? 0);
        return [
            'patterns' => (int)($patterns['patterns'] ?? 0),
            'pending_patterns' => (int)($patterns['pending_patterns'] ?? 0),
            'proposed_patterns' => (int)($patterns['proposed_patterns'] ?? 0),
            'deferred_patterns' => (int)($patterns['deferred_patterns'] ?? 0),
            'transactions' => $transactions,
            'proposed_transactions' => $proposedTransactions,
            'deferred_transactions' => (int)($patterns['deferred_transactions'] ?? 0),
            'coverage_percent' => $transactions > 0 ? round(($proposedTransactions / $transactions) * 100, 1) : 0.0,
            'proposals' => (int)($proposals['proposals'] ?? 0),
            'pending_proposals' => (int)($proposals['pending_proposals'] ?? 0),
            'approved_proposals' => (int)($proposals['approved_proposals'] ?? 0),
            'rejected_proposals' => (int)($proposals['rejected_proposals'] ?? 0),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function listProposals(int $runId): array {
        $stmt = $this->db->prepare(
            'SELECT p.*, c.name AS category_name FROM tag_taxonomy_proposals p '
            . 'LEFT JOIN categories c ON c.id = p.category_id WHERE p.run_id = :run_id '
            . "ORDER BY CASE p.status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END, p.transaction_count DESC, p.canonical_name"
        );
        $stmt->execute(['run_id' => $runId]);
        $proposals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $patternStmt = $this->db->prepare(
            'SELECT id, proposal_id, alias, direction, transaction_count, absolute_amount, confidence, current_tags '
            . 'FROM tag_taxonomy_patterns WHERE run_id = :run_id AND proposal_id IS NOT NULL '
            . 'ORDER BY transaction_count DESC, id ASC'
        );
        $patternStmt->execute(['run_id' => $runId]);
        $patternsByProposal = [];
        foreach ($patternStmt->fetchAll(PDO::FETCH_ASSOC) as $pattern) {
            $proposalId = (int)$pattern['proposal_id'];
            $current = json_decode((string)$pattern['current_tags'], true);
            $patternsByProposal[$proposalId][] = [
                'id' => (int)$pattern['id'],
                'alias' => (string)$pattern['alias'],
                'direction' => (string)$pattern['direction'],
                'transaction_count' => (int)$pattern['transaction_count'],
                'absolute_amount' => (float)$pattern['absolute_amount'],
                'confidence' => $pattern['confidence'] === null ? null : (float)$pattern['confidence'],
                'current_tags' => is_array($current) ? $this->summariseCurrentTags($current) : 'Unassigned',
            ];
        }
        foreach ($proposals as &$proposal) {
            foreach (['id', 'run_id', 'category_id', 'pattern_count', 'transaction_count'] as $field) {
                $proposal[$field] = $proposal[$field] === null ? null : (int)$proposal[$field];
            }
            $proposal['confidence'] = $proposal['confidence'] === null ? null : (float)$proposal['confidence'];
            $proposal['absolute_amount'] = (float)$proposal['absolute_amount'];
            $proposal['patterns'] = $patternsByProposal[(int)$proposal['id']] ?? [];
        }
        unset($proposal);
        return $proposals;
    }

    /** @return array<string,int|float> */
    private function emptyMetrics(): array {
        return ['patterns' => 0, 'pending_patterns' => 0, 'proposed_patterns' => 0, 'deferred_patterns' => 0, 'transactions' => 0, 'proposed_transactions' => 0, 'deferred_transactions' => 0, 'coverage_percent' => 0.0, 'proposals' => 0, 'pending_proposals' => 0, 'approved_proposals' => 0, 'rejected_proposals' => 0];
    }

    private function findOrCreateProposal(int $runId, array $assignment): int {
        $select = $this->db->prepare(
            'SELECT id, status, description, category_id FROM tag_taxonomy_proposals WHERE run_id = :run_id AND canonical_name_normalized = :name LIMIT 1'
        );
        $select->execute(['run_id' => $runId, 'name' => $assignment['canonical_name_normalized']]);
        $existing = $select->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if ($existing['status'] === 'rejected') throw new RuntimeException('AI returned a previously rejected canonical tag.');
            $update = $this->db->prepare(
                "UPDATE tag_taxonomy_proposals SET status = 'pending', reviewed_by = NULL, reviewed_at = NULL, "
                . "description = CASE WHEN description IS NULL OR description = '' THEN :description ELSE description END, "
                . 'category_id = COALESCE(category_id, :category_id) WHERE id = :id'
            );
            $update->execute(['description' => $assignment['description'], 'category_id' => $assignment['category_id'], 'id' => (int)$existing['id']]);
            return (int)$existing['id'];
        }
        $insert = $this->db->prepare(
            'INSERT INTO tag_taxonomy_proposals '
            . '(run_id, canonical_name, canonical_name_normalized, description, category_id, confidence, rationale, status, origin) '
            . "VALUES (:run_id, :name, :normalized, :description, :category_id, :confidence, :rationale, 'pending', 'ai')"
        );
        $insert->execute([
            'run_id' => $runId,
            'name' => $assignment['canonical_name'],
            'normalized' => $assignment['canonical_name_normalized'],
            'description' => $assignment['description'],
            'category_id' => $assignment['category_id'],
            'confidence' => $assignment['confidence'],
            'rationale' => $assignment['rationale'],
        ]);
        return (int)$this->db->lastInsertId();
    }

    private function refreshProposalMetrics(int $runId): void {
        $stmt = $this->db->prepare(
            'UPDATE tag_taxonomy_proposals SET '
            . 'pattern_count = (SELECT COUNT(*) FROM tag_taxonomy_patterns p WHERE p.proposal_id = tag_taxonomy_proposals.id), '
            . 'transaction_count = COALESCE((SELECT SUM(p.transaction_count) FROM tag_taxonomy_patterns p WHERE p.proposal_id = tag_taxonomy_proposals.id), 0), '
            . 'absolute_amount = COALESCE((SELECT SUM(p.absolute_amount) FROM tag_taxonomy_patterns p WHERE p.proposal_id = tag_taxonomy_proposals.id), 0), '
            . 'confidence = (SELECT AVG(p.confidence) FROM tag_taxonomy_patterns p WHERE p.proposal_id = tag_taxonomy_proposals.id) '
            . 'WHERE run_id = :run_id'
        );
        $stmt->execute(['run_id' => $runId]);
    }

    /** @return array<string,mixed> */
    private function requireRun(int $runId): array {
        if ($runId <= 0) throw new InvalidArgumentException('Choose a valid baseline snapshot.');
        $stmt = $this->db->prepare('SELECT * FROM tag_migration_runs WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $runId]);
        $run = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$run || empty($run['snapshot_hash'])) throw new InvalidArgumentException('The baseline snapshot was not found.');
        foreach (['id', 'transaction_count', 'eligible_count'] as $field) $run[$field] = (int)$run[$field];
        return $run;
    }

    /** @return array<string,mixed> */
    private function requireStagingRun(int $runId): array {
        $run = $this->requireRun($runId);
        if ($run['status'] !== 'staging') throw new RuntimeException('This discovery run is not open for staging changes.');
        return $run;
    }

    /** @return array<string,mixed> */
    private function requireLockedStagingRun(int $runId): array {
        if (!$this->db->inTransaction()) throw new RuntimeException('A staging lock requires an active database transaction.');
        if ($runId <= 0) throw new InvalidArgumentException('Choose a valid baseline snapshot.');
        $lockSuffix = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $stmt = $this->db->prepare('SELECT * FROM tag_migration_runs WHERE id = :id LIMIT 1' . $lockSuffix);
        $stmt->execute(['id' => $runId]);
        $run = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$run || empty($run['snapshot_hash'])) throw new InvalidArgumentException('The baseline snapshot was not found.');
        if ($run['status'] !== 'staging') throw new RuntimeException('This discovery run is not open for staging changes.');
        foreach (['id', 'transaction_count', 'eligible_count'] as $field) $run[$field] = (int)$run[$field];
        return $run;
    }

    /** @return array<string,mixed> */
    private function requirePendingPattern(int $runId, int $patternId): array {
        $stmt = $this->db->prepare("SELECT id FROM tag_taxonomy_patterns WHERE id = :id AND run_id = :run_id AND status = 'pending' LIMIT 1");
        $stmt->execute(['id' => $patternId, 'run_id' => $runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new InvalidArgumentException('The AI response referred to a pattern that is no longer pending.');
        $row['id'] = (int)$row['id'];
        return $row;
    }

    /** @return array<string,mixed> */
    private function requireProposal(int $runId, int $proposalId): array {
        $stmt = $this->db->prepare('SELECT * FROM tag_taxonomy_proposals WHERE id = :id AND run_id = :run_id LIMIT 1');
        $stmt->execute(['id' => $proposalId, 'run_id' => $runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new InvalidArgumentException('The staged canonical tag was not found.');
        $row['id'] = (int)$row['id'];
        return $row;
    }

    private function categoryExists(int $categoryId): bool {
        $stmt = $this->db->prepare('SELECT id FROM categories WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $categoryId]);
        return $stmt->fetchColumn() !== false;
    }

    private function countForRun(string $table, int $runId): int {
        if (!in_array($table, ['tag_taxonomy_patterns', 'tag_taxonomy_proposals', 'transaction_tag_proposals'], true)) {
            throw new InvalidArgumentException('Unsupported staging table.');
        }
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$table} WHERE run_id = :run_id");
        $stmt->execute(['run_id' => $runId]);
        return (int)$stmt->fetchColumn();
    }

    private function summariseCurrentTags(array $tags): string {
        $parts = [];
        arsort($tags);
        foreach (array_slice($tags, 0, 4, true) as $name => $count) $parts[] = $name . ' (' . (int)$count . ')';
        return implode(', ', $parts);
    }

    /** @param array<int,array{0:int,1:int,2:int,3:?int}> $rows */
    private function insertTransactionBatch(array $rows): void {
        if (empty($rows)) return;
        $placeholders = implode(',', array_fill(0, count($rows), '(?,?,?,?)'));
        $values = [];
        foreach ($rows as $row) {
            foreach ($row as $value) $values[] = $value;
        }
        $stmt = $this->db->prepare(
            'INSERT INTO transaction_tag_proposals (run_id, transaction_id, pattern_id, current_tag_id) VALUES ' . $placeholders
        );
        $stmt->execute($values);
    }

    private function requireSchema(): void {
        if (!$this->schemaReady()) {
            throw new RuntimeException('Phase 2 database structures are missing. Run Database Health and apply the catalogue repairs first.');
        }
    }
}
?>
