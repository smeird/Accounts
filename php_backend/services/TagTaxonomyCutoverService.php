<?php
// Phase 3 applies a reviewed taxonomy as one auditable transaction. Financial
// fields are never written; the immutable Phase 1 snapshot remains the source
// of truth for classification rollback.
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../models/Tag.php';
require_once __DIR__ . '/../models/TagAlias.php';
require_once __DIR__ . '/TagMigrationSafetyService.php';

class TagTaxonomyCutoverService {
    private const SUMMARY_VERSION = 1;

    private $db;
    private $safety;

    public function __construct($db = null) {
        $this->db = $db ?: Database::getConnection();
        $this->safety = new TagMigrationSafetyService($this->db);
    }

    public function schemaReady(): bool {
        try {
            $this->db->query('SELECT `direction` FROM `tag_aliases` WHERE 1 = 0');
            $this->db->query('SELECT `cutover_summary` FROM `tag_migration_runs` WHERE 1 = 0');
            return $this->safety->schemaReady();
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    public function overview(?int $requestedRunId = null): array {
        $this->requireSchema();
        $runs = $this->db->query(
            "SELECT id, name, status, transaction_count, eligible_count, protected_transfer_count, "
            . "protected_ignore_count, created_at, ready_at, applied_at, rolled_back_at "
            . "FROM tag_migration_runs WHERE status IN ('ready','applied','rolled_back') ORDER BY id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($runs as &$run) {
            foreach (['id', 'transaction_count', 'eligible_count', 'protected_transfer_count', 'protected_ignore_count'] as $field) {
                $run[$field] = (int)($run[$field] ?? 0);
            }
        }
        unset($run);

        $runId = $requestedRunId && $requestedRunId > 0 ? $requestedRunId : null;
        if ($runId === null && $runs) $runId = (int)$runs[0]['id'];
        return [
            'schema_ready' => true,
            'runs' => $runs,
            'selected_run' => $runId ? $this->preview($runId) : null,
        ];
    }

    /** @return array<string,mixed> */
    public function preview(int $runId): array {
        $this->requireSchema();
        $run = $this->getRun($runId);
        $snapshot = $this->safety->rollbackPreview($runId);
        $proposals = $this->proposalPlan($runId);
        $metrics = $this->metrics($runId, $proposals);
        $blockers = $snapshot['blockers'];

        if (!in_array($run['status'], ['ready', 'applied', 'rolled_back'], true)) {
            $blockers[] = 'The taxonomy must be fully reviewed and marked ready before cutover.';
        }
        if ((int)$metrics['approved_proposals'] === 0) {
            $blockers[] = 'There are no approved canonical tags to apply.';
        }
        if ((int)$metrics['unresolved_proposed_patterns'] > 0) {
            $blockers[] = $metrics['unresolved_proposed_patterns'] . ' analysed pattern(s) do not resolve to an approved tag.';
        }
        foreach ($this->directionConflicts($runId) as $conflict) {
            $blockers[] = 'The ' . $conflict['direction'] . ' alias “' . $conflict['alias_normalized'] . '” points to more than one approved tag.';
        }
        if ((float)$metrics['coverage_percent'] < 95.0) {
            $blockers[] = 'Approved transaction coverage is below the agreed 95% cutover threshold.';
        }
        if ((int)$metrics['staged_transactions'] !== (int)$run['eligible_count']) {
            $blockers[] = 'The staged transaction manifest does not reconcile to the immutable eligible snapshot count.';
        }
        if ((int)$metrics['transaction_proposal_rows'] !== (int)$run['eligible_count']
            || (int)$metrics['assigned_transaction_proposals'] !== (int)$metrics['approved_transactions']) {
            $blockers[] = 'Transaction-level staging does not reconcile to the reviewed pattern totals.';
        }

        $canRollback = false;
        if ($run['status'] === 'applied' && !empty($run['cutover_summary']) && $snapshot['restorable']) {
            $canRollback = $this->rollbackPreview($runId)['can_rollback'];
        }
        return [
            'run' => $run,
            'snapshot' => $snapshot,
            'metrics' => $metrics,
            'financial_fingerprint' => $this->financialFingerprint(),
            'proposals' => $proposals,
            'blockers' => array_values(array_unique($blockers)),
            'can_apply' => $run['status'] === 'ready' && empty($blockers),
            'can_rollback' => $canRollback,
        ];
    }

    /** @return array<string,mixed> */
    public function apply(int $runId, string $actor): array {
        $this->requireSchema();
        $this->db->beginTransaction();
        try {
            $run = $this->lockRun($runId);
            if ($run['status'] !== 'ready') {
                throw new RuntimeException('Only a reviewed taxonomy in ready state can be applied.');
            }
            if (!empty($run['cutover_summary'])) {
                throw new RuntimeException('This taxonomy already has a cutover audit record.');
            }
            $preview = $this->preview($runId);
            if (!$preview['can_apply']) {
                throw new RuntimeException('The taxonomy cannot be applied: ' . implode(' ', $preview['blockers']));
            }

            $beforeFinancial = $this->financialFingerprint();
            $untouchedHash = $this->classificationHash($runId, false);
            $proposalPlan = $preview['proposals'];
            $audit = [
                'version' => self::SUMMARY_VERSION,
                'run_id' => $runId,
                'applied_by' => substr(trim($actor), 0, 100),
                'applied_at' => gmdate('c'),
                'financial_before' => $beforeFinancial,
                'untouched_classification_hash' => $untouchedHash,
                'metrics' => $preview['metrics'],
                'proposals' => [],
                'deprecated_tags' => [],
            ];
            $targetTagIds = [];
            $oldTagIds = $this->candidateOldTagIds($runId);

            foreach ($proposalPlan as $plan) {
                $proposalId = (int)$plan['proposal_id'];
                $tagBefore = null;
                $tagCreated = false;
                if (!empty($plan['existing_tag_id'])) {
                    $tagId = (int)$plan['existing_tag_id'];
                    $tagBefore = $this->tagRow($tagId);
                    $activate = $this->db->prepare("UPDATE tags SET status = 'active', merged_into_tag_id = NULL WHERE id = :id");
                    $activate->execute(['id' => $tagId]);
                } else {
                    $insertTag = $this->db->prepare(
                        "INSERT INTO tags (name, name_normalized, keyword, description, origin, status, merged_into_tag_id) "
                        . "VALUES (:name, :normalized, NULL, :description, :origin, 'active', NULL)"
                    );
                    $insertTag->execute([
                        'name' => $plan['canonical_name'],
                        'normalized' => $plan['canonical_name_normalized'],
                        'description' => $plan['description'],
                        'origin' => in_array($plan['origin'], ['ai', 'manual'], true) ? $plan['origin'] : 'manual',
                    ]);
                    $tagId = (int)$this->db->lastInsertId();
                    $tagCreated = true;
                }
                $targetTagIds[$tagId] = true;

                $categoryBefore = $this->categoryIdsForTag($tagId);
                $deleteCategory = $this->db->prepare('DELETE FROM category_tags WHERE tag_id = :tag_id');
                $deleteCategory->execute(['tag_id' => $tagId]);
                if ($plan['category_id'] !== null) {
                    $insertCategory = $this->db->prepare('INSERT INTO category_tags (category_id, tag_id) VALUES (:category_id, :tag_id)');
                    $insertCategory->execute(['category_id' => $plan['category_id'], 'tag_id' => $tagId]);
                }

                $aliasAudit = [];
                foreach ($plan['aliases'] as $alias) {
                    $existingAlias = $this->aliasByKey($alias['alias_normalized'], $alias['direction']);
                    $aliasBefore = $existingAlias ?: null;
                    if ($existingAlias) {
                        $aliasId = (int)$existingAlias['id'];
                        $updateAlias = $this->db->prepare(
                            "UPDATE tag_aliases SET tag_id = :tag_id, alias = :alias, match_type = 'contains', "
                            . "active = 1, origin = :origin, confidence = :confidence, support_count = :support_count "
                            . "WHERE id = :id"
                        );
                        $updateAlias->execute([
                            'tag_id' => $tagId,
                            'alias' => $alias['alias'],
                            'origin' => $plan['origin'],
                            'confidence' => $alias['confidence'],
                            'support_count' => $alias['support_count'],
                            'id' => $aliasId,
                        ]);
                        $aliasCreated = false;
                    } else {
                        $insertAlias = $this->db->prepare(
                            "INSERT INTO tag_aliases (tag_id, alias, alias_normalized, match_type, direction, active, origin, confidence, support_count) "
                            . "VALUES (:tag_id, :alias, :normalized, 'contains', :direction, 1, :origin, :confidence, :support_count)"
                        );
                        $insertAlias->execute([
                            'tag_id' => $tagId,
                            'alias' => $alias['alias'],
                            'normalized' => $alias['alias_normalized'],
                            'direction' => $alias['direction'],
                            'origin' => $plan['origin'],
                            'confidence' => $alias['confidence'],
                            'support_count' => $alias['support_count'],
                        ]);
                        $aliasId = (int)$this->db->lastInsertId();
                        $aliasCreated = true;
                    }
                    $aliasAudit[] = [
                        'id' => $aliasId,
                        'created' => $aliasCreated,
                        'before' => $aliasBefore,
                        'after' => $this->aliasRow($aliasId),
                    ];
                }

                $transactionIds = $this->candidateIdsForProposal($runId, $proposalId);
                $updated = $this->retagTransactions($transactionIds, $tagId, $plan['category_id'], $plan['segment_id']);
                $audit['proposals'][] = [
                    'proposal_id' => $proposalId,
                    'tag_id' => $tagId,
                    'tag_created' => $tagCreated,
                    'tag_before' => $tagBefore,
                    'tag_after' => $this->tagRow($tagId),
                    'category_ids_before' => $categoryBefore,
                    'category_id_after' => $plan['category_id'],
                    'segment_id_after' => $plan['segment_id'],
                    'updated_transactions' => $updated,
                    'transaction_ids' => $transactionIds,
                    'aliases' => $aliasAudit,
                ];
            }

            foreach ($oldTagIds as $tagId) {
                if (isset($targetTagIds[$tagId]) || !$this->canDeprecateTag($tagId)) continue;
                $tagBefore = $this->tagRow($tagId);
                if (!$tagBefore || strtoupper(trim((string)$tagBefore['name'])) === 'IGNORE') continue;
                $aliasRows = $this->aliasesForTag($tagId);
                $this->db->prepare("UPDATE tags SET status = 'deprecated' WHERE id = :id")->execute(['id' => $tagId]);
                $this->db->prepare('UPDATE tag_aliases SET active = 0 WHERE tag_id = :id')->execute(['id' => $tagId]);
                $audit['deprecated_tags'][] = [
                    'tag_id' => $tagId,
                    'tag_before' => $tagBefore,
                    'tag_after' => $this->tagRow($tagId),
                    'aliases_before' => $aliasRows,
                    'aliases_after' => $this->aliasesForTag($tagId),
                ];
            }

            $afterFinancial = $this->financialFingerprint();
            if ($afterFinancial !== $beforeFinancial) {
                throw new RuntimeException('Financial reconciliation failed; the cutover was cancelled.');
            }
            if ($this->classificationHash($runId, false) !== $untouchedHash) {
                throw new RuntimeException('A protected, deferred, or post-snapshot classification changed; the cutover was cancelled.');
            }
            $mismatches = $this->assignmentMismatchCount($runId, $audit['proposals']);
            if ($mismatches !== 0) {
                throw new RuntimeException('Classification reconciliation found ' . $mismatches . ' unexpected assignment(s); the cutover was cancelled.');
            }
            $audit['financial_after'] = $afterFinancial;
            $audit['assignment_mismatches'] = 0;
            $encodedAudit = json_encode($audit, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encodedAudit === false) throw new RuntimeException('The cutover audit record could not be encoded.');
            $updateRun = $this->db->prepare(
                "UPDATE tag_migration_runs SET status = 'applied', applied_at = CURRENT_TIMESTAMP, rolled_back_at = NULL, cutover_summary = :summary WHERE id = :id"
            );
            $updateRun->execute(['summary' => $encodedAudit, 'id' => $runId]);
            $this->db->commit();
            Tag::clearMatchCaches();
            return [
                'status' => 'applied',
                'message' => 'The reviewed taxonomy was applied and reconciled without changing financial values.',
                'cutover' => $this->preview($runId),
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function rollbackPreview(int $runId): array {
        $this->requireSchema();
        $run = $this->getRun($runId);
        $snapshot = $this->safety->rollbackPreview($runId);
        $blockers = $snapshot['blockers'];
        $audit = $this->decodeAudit($run);
        if ($run['status'] !== 'applied') $blockers[] = 'Only an applied taxonomy can be rolled back.';
        if (!$audit) $blockers[] = 'The cutover audit record is missing or invalid.';
        if ($audit) {
            $blockers = array_merge($blockers, $this->rollbackStateBlockers($runId, $audit));
        }
        return [
            'run' => $run,
            'snapshot' => $snapshot,
            'blockers' => array_values(array_unique($blockers)),
            'can_rollback' => $run['status'] === 'applied' && $audit && empty($blockers),
        ];
    }

    /** @return array<string,mixed> */
    public function rollback(int $runId, string $actor): array {
        $check = $this->rollbackPreview($runId);
        if (!$check['can_rollback']) {
            throw new RuntimeException('The cutover cannot be rolled back until every audit blocker is resolved.');
        }
        $this->db->beginTransaction();
        try {
            $run = $this->lockRun($runId);
            if ($run['status'] !== 'applied') throw new RuntimeException('This taxonomy is no longer applied.');
            $audit = $this->decodeAudit($run);
            if (!$audit) throw new RuntimeException('The cutover audit record is unavailable.');
            $lockedCheck = $this->rollbackPreview($runId);
            if (!$lockedCheck['can_rollback']) {
                throw new RuntimeException('The live taxonomy changed while rollback was being prepared.');
            }
            $financialBefore = $this->financialFingerprint();
            $this->restoreAuditedClassifications($runId, $audit);

            foreach (array_reverse($audit['deprecated_tags'] ?? []) as $entry) {
                $this->restoreTagState($entry['tag_before']);
                foreach ($entry['aliases_before'] ?? [] as $alias) $this->restoreAliasState($alias);
            }
            foreach (array_reverse($audit['proposals'] ?? []) as $entry) {
                foreach (array_reverse($entry['aliases'] ?? []) as $alias) {
                    if (!empty($alias['created'])) {
                        $this->db->prepare('DELETE FROM tag_aliases WHERE id = :id')->execute(['id' => (int)$alias['id']]);
                    } elseif (!empty($alias['before'])) {
                        $this->restoreAliasState($alias['before']);
                    }
                }
                $this->db->prepare('DELETE FROM category_tags WHERE tag_id = :tag_id')->execute(['tag_id' => (int)$entry['tag_id']]);
                foreach ($entry['category_ids_before'] ?? [] as $categoryId) {
                    $this->db->prepare('INSERT INTO category_tags (category_id, tag_id) VALUES (:category_id, :tag_id)')
                        ->execute(['category_id' => (int)$categoryId, 'tag_id' => (int)$entry['tag_id']]);
                }
                if (!empty($entry['tag_created'])) {
                    $this->db->prepare('DELETE FROM tags WHERE id = :id')->execute(['id' => (int)$entry['tag_id']]);
                } elseif (!empty($entry['tag_before'])) {
                    $this->restoreTagState($entry['tag_before']);
                }
            }
            if ($this->financialFingerprint() !== $financialBefore) {
                throw new RuntimeException('Financial reconciliation failed during rollback.');
            }
            $after = $this->safety->rollbackPreview($runId);
            if (!$after['hash_valid'] || $this->snapshotMismatchCount($runId, $audit) !== 0) {
                throw new RuntimeException('Snapshot reconciliation failed during rollback.');
            }
            $audit['rolled_back_by'] = substr(trim($actor), 0, 100);
            $audit['rolled_back_at'] = gmdate('c');
            $encoded = json_encode($audit, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->db->prepare(
                "UPDATE tag_migration_runs SET status = 'rolled_back', rolled_back_at = CURRENT_TIMESTAMP, cutover_summary = :summary WHERE id = :id"
            )->execute(['summary' => $encoded, 'id' => $runId]);
            $this->db->commit();
            Tag::clearMatchCaches();
            return [
                'status' => 'rolled_back',
                'message' => 'The previous classifications and taxonomy relationships were restored.',
                'cutover' => $this->preview($runId),
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function proposalPlan(int $runId): array {
        $stmt = $this->db->prepare(
            "SELECT p.id AS proposal_id, p.canonical_name, p.canonical_name_normalized, p.description, p.category_id, "
            . "p.confidence, p.origin, p.pattern_count, p.transaction_count, c.name AS category_name, "
            . "c.segment_id, s.name AS segment_name "
            . "FROM tag_taxonomy_proposals p LEFT JOIN categories c ON c.id = p.category_id "
            . "LEFT JOIN segments s ON s.id = c.segment_id WHERE p.run_id = :run_id AND p.status = 'approved' "
            . "ORDER BY p.transaction_count DESC, p.canonical_name"
        );
        $stmt->execute(['run_id' => $runId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $findTag = $this->db->prepare(
            "SELECT id FROM tags WHERE name_normalized = :normalized OR "
            . "(name_normalized IS NULL AND LOWER(TRIM(name)) = :normalized) ORDER BY id LIMIT 1"
        );
        $aliasStmt = $this->db->prepare(
            "SELECT MIN(alias) AS alias, alias_normalized, direction, SUM(transaction_count) AS support_count, "
            . "MAX(confidence) AS confidence FROM tag_taxonomy_patterns "
            . "WHERE run_id = :run_id AND proposal_id = :proposal_id AND status = 'proposed' "
            . "GROUP BY alias_normalized, direction ORDER BY support_count DESC, alias_normalized"
        );
        foreach ($rows as &$row) {
            $findTag->execute(['normalized' => $row['canonical_name_normalized']]);
            $existing = $findTag->fetchColumn();
            $row['existing_tag_id'] = $existing === false ? null : (int)$existing;
            foreach (['proposal_id', 'pattern_count', 'transaction_count'] as $field) $row[$field] = (int)$row[$field];
            foreach (['category_id', 'segment_id'] as $field) $row[$field] = $row[$field] === null ? null : (int)$row[$field];
            $aliasStmt->execute(['run_id' => $runId, 'proposal_id' => $row['proposal_id']]);
            $row['aliases'] = array_map(function($alias) {
                return [
                    'alias' => (string)$alias['alias'],
                    'alias_normalized' => (string)$alias['alias_normalized'],
                    'direction' => (string)$alias['direction'],
                    'support_count' => (int)$alias['support_count'],
                    'confidence' => $alias['confidence'] === null ? null : (float)$alias['confidence'],
                ];
            }, $aliasStmt->fetchAll(PDO::FETCH_ASSOC));
        }
        unset($row);
        return $rows;
    }

    /** @return array<string,int|float> */
    private function metrics(int $runId, array $proposals): array {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS patterns, "
            . "SUM(CASE WHEN p.status = 'proposed' AND d.status = 'approved' THEN 1 ELSE 0 END) AS approved_patterns, "
            . "SUM(CASE WHEN p.status = 'excluded' THEN 1 ELSE 0 END) AS deferred_patterns, "
            . "SUM(CASE WHEN p.status = 'proposed' AND (d.id IS NULL OR d.status <> 'approved') THEN 1 ELSE 0 END) AS unresolved, "
            . "COALESCE(SUM(p.transaction_count),0) AS transactions, "
            . "COALESCE(SUM(CASE WHEN p.status = 'proposed' AND d.status = 'approved' THEN p.transaction_count ELSE 0 END),0) AS covered, "
            . "COALESCE(SUM(CASE WHEN p.status = 'excluded' THEN p.transaction_count ELSE 0 END),0) AS deferred_transactions "
            . "FROM tag_taxonomy_patterns p LEFT JOIN tag_taxonomy_proposals d ON d.id = p.proposal_id WHERE p.run_id = :run_id"
        );
        $stmt->execute(['run_id' => $runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $counts = $this->candidateCounts($runId);
        $transactionStmt = $this->db->prepare(
            "SELECT COUNT(*) AS rows_count, "
            . "SUM(CASE WHEN d.status = 'approved' AND p.status = 'proposed' THEN 1 ELSE 0 END) AS assigned_count "
            . "FROM transaction_tag_proposals x LEFT JOIN tag_taxonomy_patterns p ON p.id = x.pattern_id "
            . "LEFT JOIN tag_taxonomy_proposals d ON d.id = x.proposal_id WHERE x.run_id = :run_id"
        );
        $transactionStmt->execute(['run_id' => $runId]);
        $transactionRows = $transactionStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $aliases = 0;
        $newTags = 0;
        $categories = [];
        foreach ($proposals as $proposal) {
            $aliases += count($proposal['aliases']);
            if ($proposal['existing_tag_id'] === null) $newTags++;
            if ($proposal['category_id'] !== null) $categories[$proposal['category_id']] = true;
        }
        $transactions = (int)($row['transactions'] ?? 0);
        $covered = (int)($row['covered'] ?? 0);
        return [
            'approved_proposals' => count($proposals),
            'new_tags' => $newTags,
            'reused_tags' => count($proposals) - $newTags,
            'direction_aware_aliases' => $aliases,
            'mapped_categories' => count($categories),
            'patterns' => (int)($row['patterns'] ?? 0),
            'approved_patterns' => (int)($row['approved_patterns'] ?? 0),
            'deferred_patterns' => (int)($row['deferred_patterns'] ?? 0),
            'unresolved_proposed_patterns' => (int)($row['unresolved'] ?? 0),
            'staged_transactions' => $transactions,
            'approved_transactions' => $covered,
            'transaction_proposal_rows' => (int)($transactionRows['rows_count'] ?? 0),
            'assigned_transaction_proposals' => (int)($transactionRows['assigned_count'] ?? 0),
            'deferred_transactions' => (int)($row['deferred_transactions'] ?? 0),
            'coverage_percent' => $transactions > 0 ? round(($covered / $transactions) * 100, 1) : 0.0,
            'transactions_to_retag' => $counts['candidate'],
            'newly_protected_transactions' => $counts['newly_protected'],
            'post_snapshot_transactions_untouched' => $counts['post_snapshot'],
        ];
    }

    /** @return array<string,int> */
    private function candidateCounts(int $runId): array {
        $stmt = $this->db->prepare(
            "SELECT "
            . "SUM(CASE WHEN " . $this->candidateCondition() . " THEN 1 ELSE 0 END) AS candidate, "
            . "SUM(CASE WHEN s.eligible = 1 AND d.status = 'approved' AND p.status = 'proposed' "
            . "AND (t.transfer_id IS NOT NULL OR UPPER(TRIM(COALESCE(current_tag.name, ''))) = 'IGNORE') THEN 1 ELSE 0 END) AS newly_protected "
            . "FROM transactions t LEFT JOIN transaction_classification_snapshots s ON s.run_id = :run_id AND s.transaction_id = t.id "
            . "LEFT JOIN transaction_tag_proposals x ON x.run_id = :run_id AND x.transaction_id = t.id "
            . "LEFT JOIN tag_taxonomy_patterns p ON p.id = x.pattern_id LEFT JOIN tag_taxonomy_proposals d ON d.id = x.proposal_id "
            . "LEFT JOIN tags current_tag ON current_tag.id = t.tag_id"
        );
        $stmt->execute(['run_id' => $runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $newStmt = $this->db->prepare(
            'SELECT COUNT(*) FROM transactions t LEFT JOIN transaction_classification_snapshots s '
            . 'ON s.run_id = :run_id AND s.transaction_id = t.id WHERE s.transaction_id IS NULL'
        );
        $newStmt->execute(['run_id' => $runId]);
        return [
            'candidate' => (int)($row['candidate'] ?? 0),
            'newly_protected' => (int)($row['newly_protected'] ?? 0),
            'post_snapshot' => (int)$newStmt->fetchColumn(),
        ];
    }

    private function candidateCondition(): string {
        return "s.eligible = 1 AND d.status = 'approved' AND p.status = 'proposed' "
            . "AND t.transfer_id IS NULL AND UPPER(TRIM(COALESCE(current_tag.name, ''))) <> 'IGNORE'";
    }

    /** @return array<int,array<string,mixed>> */
    private function directionConflicts(int $runId): array {
        $stmt = $this->db->prepare(
            "SELECT p.alias_normalized, p.direction, COUNT(DISTINCT p.proposal_id) AS destinations "
            . "FROM tag_taxonomy_patterns p INNER JOIN tag_taxonomy_proposals d ON d.id = p.proposal_id "
            . "WHERE p.run_id = :run_id AND p.status = 'proposed' AND d.status = 'approved' "
            . "GROUP BY p.alias_normalized, p.direction HAVING COUNT(DISTINCT p.proposal_id) > 1"
        );
        $stmt->execute(['run_id' => $runId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,string|int> */
    private function financialFingerprint(): array {
        $row = $this->db->query(
            "SELECT COUNT(*) AS transaction_count, COALESCE(SUM(amount),0) AS signed_total, "
            . "COALESCE(SUM(ABS(amount)),0) AS absolute_total, COALESCE(MIN(id),0) AS first_id, COALESCE(MAX(id),0) AS last_id FROM transactions"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'transaction_count' => (int)($row['transaction_count'] ?? 0),
            'signed_total' => number_format((float)($row['signed_total'] ?? 0), 2, '.', ''),
            'absolute_total' => number_format((float)($row['absolute_total'] ?? 0), 2, '.', ''),
            'first_id' => (int)($row['first_id'] ?? 0),
            'last_id' => (int)($row['last_id'] ?? 0),
        ];
    }

    private function classificationHash(int $runId, bool $candidate): string {
        $sql = "SELECT t.id, t.tag_id, t.category_id, t.segment_id FROM transactions t "
            . "LEFT JOIN transaction_classification_snapshots s ON s.run_id = :run_id AND s.transaction_id = t.id "
            . "LEFT JOIN transaction_tag_proposals x ON x.run_id = :run_id AND x.transaction_id = t.id "
            . "LEFT JOIN tag_taxonomy_patterns p ON p.id = x.pattern_id LEFT JOIN tag_taxonomy_proposals d ON d.id = x.proposal_id "
            . "LEFT JOIN tags current_tag ON current_tag.id = t.tag_id WHERE "
            . ($candidate ? '' : 'NOT (') . $this->candidateCondition() . ($candidate ? '' : ')') . ' ORDER BY t.id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['run_id' => $runId]);
        $context = hash_init('sha256');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            hash_update($context, implode('|', [
                (string)$row['id'],
                $row['tag_id'] === null ? '~' : (string)$row['tag_id'],
                $row['category_id'] === null ? '~' : (string)$row['category_id'],
                $row['segment_id'] === null ? '~' : (string)$row['segment_id'],
            ]) . "\n");
        }
        return hash_final($context);
    }

    /** @return array<int,int> */
    private function candidateOldTagIds(int $runId): array {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT t.tag_id FROM transactions t "
            . "LEFT JOIN transaction_classification_snapshots s ON s.run_id = :run_id AND s.transaction_id = t.id "
            . "LEFT JOIN transaction_tag_proposals x ON x.run_id = :run_id AND x.transaction_id = t.id "
            . "LEFT JOIN tag_taxonomy_patterns p ON p.id = x.pattern_id LEFT JOIN tag_taxonomy_proposals d ON d.id = x.proposal_id "
            . "LEFT JOIN tags current_tag ON current_tag.id = t.tag_id WHERE " . $this->candidateCondition() . ' AND t.tag_id IS NOT NULL'
        );
        $stmt->execute(['run_id' => $runId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<int,int> */
    private function candidateIdsForProposal(int $runId, int $proposalId): array {
        $stmt = $this->db->prepare(
            "SELECT t.id FROM transactions t "
            . "LEFT JOIN transaction_classification_snapshots s ON s.run_id = :run_id AND s.transaction_id = t.id "
            . "LEFT JOIN transaction_tag_proposals x ON x.run_id = :run_id AND x.transaction_id = t.id "
            . "LEFT JOIN tag_taxonomy_patterns p ON p.id = x.pattern_id LEFT JOIN tag_taxonomy_proposals d ON d.id = x.proposal_id "
            . "LEFT JOIN tags current_tag ON current_tag.id = t.tag_id WHERE " . $this->candidateCondition()
            . " AND x.proposal_id = :proposal_id ORDER BY t.id"
        );
        $stmt->execute(['run_id' => $runId, 'proposal_id' => $proposalId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function retagTransactions(array $transactionIds, int $tagId, ?int $categoryId, ?int $segmentId): int {
        $updated = 0;
        foreach (array_chunk($transactionIds, 500) as $chunk) {
            if (!$chunk) continue;
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->prepare(
                "UPDATE transactions SET tag_id = ?, category_id = ?, segment_id = ? WHERE id IN ($marks) "
                . "AND transfer_id IS NULL AND UPPER(TRIM(COALESCE((SELECT name FROM tags WHERE id = transactions.tag_id), ''))) <> 'IGNORE'"
            );
            $stmt->execute(array_merge([$tagId, $categoryId, $segmentId], $chunk));
            $updated += $stmt->rowCount();
        }
        return $updated;
    }

    private function assignmentMismatchCount(int $runId, array $auditProposals): int {
        $total = 0;
        foreach ($auditProposals as $proposal) {
            foreach (array_chunk(array_map('intval', $proposal['transaction_ids'] ?? []), 500) as $chunk) {
                if (!$chunk) continue;
                $marks = implode(',', array_fill(0, count($chunk), '?'));
                $stmt = $this->db->prepare(
                    "SELECT COUNT(*) FROM transactions t WHERE t.id IN ($marks) "
                    . "AND (COALESCE(CAST(t.tag_id AS CHAR),'~') <> COALESCE(CAST(? AS CHAR),'~') "
                    . "OR COALESCE(CAST(t.category_id AS CHAR),'~') <> COALESCE(CAST(? AS CHAR),'~') "
                    . "OR COALESCE(CAST(t.segment_id AS CHAR),'~') <> COALESCE(CAST(? AS CHAR),'~'))"
                );
                $stmt->execute(array_merge($chunk, [
                    (int)$proposal['tag_id'], $proposal['category_id_after'], $proposal['segment_id_after'],
                ]));
                $total += (int)$stmt->fetchColumn();
            }
        }
        return $total;
    }

    private function canDeprecateTag(int $tagId): bool {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM transactions WHERE tag_id = :id');
        $stmt->execute(['id' => $tagId]);
        return (int)$stmt->fetchColumn() === 0;
    }

    /** @return array<int,string> */
    private function rollbackStateBlockers(int $runId, array $audit): array {
        $blockers = [];
        foreach ($audit['proposals'] ?? [] as $proposal) {
            $tagId = (int)$proposal['tag_id'];
            $currentTag = $this->tagRow($tagId);
            if (!$currentTag || !$this->sameTagState($currentTag, $proposal['tag_after'] ?? [])) {
                $blockers[] = 'A canonical tag changed after cutover.';
            }
            if (!empty($proposal['tag_created'])) {
                $stmt = $this->db->prepare(
                    'SELECT COUNT(*) FROM transactions t LEFT JOIN transaction_classification_snapshots s '
                    . 'ON s.run_id = :run_id AND s.transaction_id = t.id WHERE t.tag_id = :tag_id AND s.transaction_id IS NULL'
                );
                $stmt->execute(['run_id' => $runId, 'tag_id' => $tagId]);
                if ((int)$stmt->fetchColumn() > 0) {
                    $blockers[] = 'A tag created by cutover is now used by a post-snapshot transaction.';
                }
                $expectedAliasIds = array_map(function($entry) { return (int)$entry['id']; }, $proposal['aliases'] ?? []);
                $currentAliasIds = array_map(function($entry) { return (int)$entry['id']; }, $this->aliasesForTag($tagId));
                sort($expectedAliasIds);
                sort($currentAliasIds);
                if ($expectedAliasIds !== $currentAliasIds) {
                    $blockers[] = 'A tag created by cutover now has additional or missing aliases.';
                }
            }
            $currentCategories = $this->categoryIdsForTag($tagId);
            $expected = $proposal['category_id_after'] === null ? [] : [(int)$proposal['category_id_after']];
            if ($currentCategories !== $expected) $blockers[] = 'A reviewed tag-to-category mapping changed after cutover.';
            foreach ($proposal['aliases'] ?? [] as $aliasAudit) {
                $current = $this->aliasRow((int)$aliasAudit['id']);
                if (!$current || !$this->sameAliasState($current, $aliasAudit['after'] ?? [])) {
                    $blockers[] = 'A direction-aware alias changed after cutover.';
                }
            }
        }
        foreach ($audit['deprecated_tags'] ?? [] as $entry) {
            $currentTag = $this->tagRow((int)$entry['tag_id']);
            if (!$currentTag || !$this->sameTagState($currentTag, $entry['tag_after'] ?? [])) {
                $blockers[] = 'A deprecated legacy tag changed after cutover.';
            }
            $expectedAliases = $entry['aliases_after'] ?? [];
            foreach ($expectedAliases as $expectedAlias) {
                $currentAlias = $this->aliasRow((int)$expectedAlias['id']);
                if (!$currentAlias || !$this->sameAliasState($currentAlias, $expectedAlias)) {
                    $blockers[] = 'A deprecated legacy alias changed after cutover.';
                }
            }
        }
        if ($this->assignmentMismatchCount($runId, $audit['proposals'] ?? []) > 0) {
            $blockers[] = 'One or more cutover classifications were edited after application.';
        }
        return $blockers;
    }

    private function restoreAuditedClassifications(int $runId, array $audit): void {
        $transactionIds = [];
        foreach ($audit['proposals'] ?? [] as $proposal) {
            foreach ($proposal['transaction_ids'] ?? [] as $transactionId) $transactionIds[(int)$transactionId] = true;
        }
        $transactionIds = array_keys($transactionIds);
        $driver = (string)$this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            foreach (array_chunk($transactionIds, 500) as $chunk) {
                if (!$chunk) continue;
                $marks = implode(',', array_fill(0, count($chunk), '?'));
                $stmt = $this->db->prepare(
                    'UPDATE transactions t INNER JOIN transaction_classification_snapshots s ON s.transaction_id = t.id '
                    . "SET t.tag_id = s.tag_id, t.category_id = s.category_id, t.segment_id = s.segment_id WHERE s.run_id = ? AND t.id IN ($marks)"
                );
                $stmt->execute(array_merge([$runId], $chunk));
            }
        } else {
            $read = $this->db->prepare(
                'SELECT tag_id, category_id, segment_id FROM transaction_classification_snapshots WHERE run_id = ? AND transaction_id = ?'
            );
            $update = $this->db->prepare('UPDATE transactions SET tag_id = ?, category_id = ?, segment_id = ? WHERE id = ?');
            foreach ($transactionIds as $transactionId) {
                $read->execute([$runId, $transactionId]);
                $row = $read->fetch(PDO::FETCH_ASSOC);
                if (!$row) throw new RuntimeException('An audited snapshot classification is missing.');
                $update->execute([$row['tag_id'], $row['category_id'], $row['segment_id'], $transactionId]);
            }
        }
    }

    private function snapshotMismatchCount(int $runId, array $audit): int {
        $ids = [];
        foreach ($audit['proposals'] ?? [] as $proposal) {
            foreach ($proposal['transaction_ids'] ?? [] as $transactionId) $ids[(int)$transactionId] = true;
        }
        $total = 0;
        foreach (array_chunk(array_keys($ids), 500) as $chunk) {
            if (!$chunk) continue;
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM transactions t INNER JOIN transaction_classification_snapshots s ON s.transaction_id = t.id "
                . "WHERE s.run_id = ? AND t.id IN ($marks) AND ("
                . "COALESCE(CAST(t.tag_id AS CHAR),'~') <> COALESCE(CAST(s.tag_id AS CHAR),'~') OR "
                . "COALESCE(CAST(t.category_id AS CHAR),'~') <> COALESCE(CAST(s.category_id AS CHAR),'~') OR "
                . "COALESCE(CAST(t.segment_id AS CHAR),'~') <> COALESCE(CAST(s.segment_id AS CHAR),'~'))"
            );
            $stmt->execute(array_merge([$runId], $chunk));
            $total += (int)$stmt->fetchColumn();
        }
        return $total;
    }

    private function restoreTagState(array $tag): void {
        $stmt = $this->db->prepare(
            'UPDATE tags SET name = :name, name_normalized = :normalized, keyword = :keyword, description = :description, '
            . 'origin = :origin, status = :status, merged_into_tag_id = :merged WHERE id = :id'
        );
        $stmt->execute([
            'id' => (int)$tag['id'], 'name' => $tag['name'], 'normalized' => $tag['name_normalized'],
            'keyword' => $tag['keyword'], 'description' => $tag['description'], 'origin' => $tag['origin'],
            'status' => $tag['status'], 'merged' => $tag['merged_into_tag_id'],
        ]);
    }

    private function restoreAliasState(array $alias): void {
        $stmt = $this->db->prepare(
            'UPDATE tag_aliases SET tag_id = :tag_id, alias = :alias, alias_normalized = :normalized, match_type = :match_type, '
            . 'direction = :direction, active = :active, origin = :origin, confidence = :confidence, support_count = :support, '
            . 'last_matched_at = :last_matched WHERE id = :id'
        );
        $stmt->execute([
            'id' => (int)$alias['id'], 'tag_id' => (int)$alias['tag_id'], 'alias' => $alias['alias'],
            'normalized' => $alias['alias_normalized'], 'match_type' => $alias['match_type'], 'direction' => $alias['direction'],
            'active' => (int)$alias['active'], 'origin' => $alias['origin'], 'confidence' => $alias['confidence'],
            'support' => (int)$alias['support_count'], 'last_matched' => $alias['last_matched_at'],
        ]);
    }

    /** @return array<string,mixed>|null */
    private function tagRow(int $tagId): ?array {
        $stmt = $this->db->prepare('SELECT id, name, name_normalized, keyword, description, origin, status, merged_into_tag_id FROM tags WHERE id = :id');
        $stmt->execute(['id' => $tagId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    private function aliasByKey(string $normalized, string $direction): ?array {
        $stmt = $this->db->prepare('SELECT * FROM tag_aliases WHERE alias_normalized = :normalized AND direction = :direction LIMIT 1');
        $stmt->execute(['normalized' => $normalized, 'direction' => $direction]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    private function aliasRow(int $aliasId): ?array {
        $stmt = $this->db->prepare('SELECT * FROM tag_aliases WHERE id = :id');
        $stmt->execute(['id' => $aliasId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    private function aliasesForTag(int $tagId): array {
        $stmt = $this->db->prepare('SELECT * FROM tag_aliases WHERE tag_id = :id ORDER BY id');
        $stmt->execute(['id' => $tagId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,int> */
    private function categoryIdsForTag(int $tagId): array {
        $stmt = $this->db->prepare('SELECT category_id FROM category_tags WHERE tag_id = :id ORDER BY category_id');
        $stmt->execute(['id' => $tagId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function sameAliasState(array $left, array $right): bool {
        foreach (['id', 'tag_id', 'alias', 'alias_normalized', 'match_type', 'direction', 'active', 'origin', 'confidence', 'support_count'] as $field) {
            if ((string)($left[$field] ?? '') !== (string)($right[$field] ?? '')) return false;
        }
        return true;
    }

    private function sameTagState(array $left, array $right): bool {
        foreach (['id', 'name', 'name_normalized', 'keyword', 'description', 'origin', 'status', 'merged_into_tag_id'] as $field) {
            if ((string)($left[$field] ?? '') !== (string)($right[$field] ?? '')) return false;
        }
        return true;
    }

    /** @return array<string,mixed>|null */
    private function decodeAudit(array $run): ?array {
        if (empty($run['cutover_summary'])) return null;
        $audit = json_decode((string)$run['cutover_summary'], true);
        return is_array($audit) && (int)($audit['version'] ?? 0) === self::SUMMARY_VERSION ? $audit : null;
    }

    /** @return array<string,mixed> */
    private function getRun(int $runId): array {
        if ($runId <= 0) throw new InvalidArgumentException('A valid taxonomy run is required.');
        $stmt = $this->db->prepare('SELECT * FROM tag_migration_runs WHERE id = :id');
        $stmt->execute(['id' => $runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new InvalidArgumentException('Taxonomy run not found.');
        $row['id'] = (int)$row['id'];
        foreach (['transaction_count', 'eligible_count', 'protected_transfer_count', 'protected_ignore_count'] as $field) {
            $row[$field] = (int)($row[$field] ?? 0);
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function lockRun(int $runId): array {
        $sql = 'SELECT * FROM tag_migration_runs WHERE id = :id';
        if ((string)$this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $sql .= ' FOR UPDATE';
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new InvalidArgumentException('Taxonomy run not found.');
        return $row;
    }

    private function requireSchema(): void {
        if (!$this->schemaReady()) {
            throw new RuntimeException('Run Database Health and apply the Phase 3 schema repairs first.');
        }
    }
}
?>
