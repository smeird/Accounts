<?php
// Guarded reset of live tagging classifications while retaining the approved
// canonical vocabulary, hashed classification evidence, and an audit of the
// removed reusable structure.
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../models/Tag.php';
require_once __DIR__ . '/TagMigrationSafetyService.php';

class TaggingFreshStartService {
    public const CONFIRMATION = 'START FRESH';

    private $db;
    private $safety;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?: Database::getConnection();
        $this->safety = new TagMigrationSafetyService($this->db);
    }

    /** @return array<string,int|string> */
    public function preview(): array {
        $transactions = $this->db->query(
            "SELECT COUNT(*) AS total,
                SUM(CASE WHEN t.transfer_id IS NULL AND UPPER(TRIM(COALESCE(tg.name, ''))) <> 'IGNORE' THEN 1 ELSE 0 END) AS eligible,
                SUM(CASE WHEN t.transfer_id IS NULL AND UPPER(TRIM(COALESCE(tg.name, ''))) <> 'IGNORE'
                    AND (t.tag_id IS NOT NULL OR t.category_id IS NOT NULL OR t.segment_id IS NOT NULL) THEN 1 ELSE 0 END) AS classified,
                SUM(CASE WHEN t.transfer_id IS NOT NULL THEN 1 ELSE 0 END) AS transfers,
                SUM(CASE WHEN t.transfer_id IS NULL AND UPPER(TRIM(COALESCE(tg.name, ''))) = 'IGNORE' THEN 1 ELSE 0 END) AS ignored
             FROM transactions t LEFT JOIN tags tg ON tg.id = t.tag_id"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $rules = (int)$this->db->query(
            "SELECT COUNT(*) FROM tag_aliases ta INNER JOIN tags tg ON tg.id = ta.tag_id
             WHERE UPPER(TRIM(COALESCE(tg.name, ''))) <> 'IGNORE'"
        )->fetchColumn();
        $links = (int)$this->db->query(
            "SELECT COUNT(*) FROM category_tags ct INNER JOIN tags tg ON tg.id = ct.tag_id
             WHERE UPPER(TRIM(COALESCE(tg.name, ''))) <> 'IGNORE'"
        )->fetchColumn();
        $keywords = (int)$this->db->query(
            "SELECT COUNT(*) FROM tags
             WHERE keyword IS NOT NULL AND keyword <> '' AND UPPER(TRIM(COALESCE(name, ''))) <> 'IGNORE'"
        )->fetchColumn();
        $canonical = (int)$this->db->query("SELECT COUNT(*) FROM tags WHERE status = 'active'")->fetchColumn();

        return [
            'confirmation' => self::CONFIRMATION,
            'transactions' => (int)($transactions['total'] ?? 0),
            'eligible_transactions' => (int)($transactions['eligible'] ?? 0),
            'classified_transactions' => (int)($transactions['classified'] ?? 0),
            'protected_transfers' => (int)($transactions['transfers'] ?? 0),
            'protected_ignored' => (int)($transactions['ignored'] ?? 0),
            'rules_to_remove' => $rules,
            'category_links_to_clear' => $links,
            'keywords_to_clear' => $keywords,
            'canonical_tags_retained' => $canonical,
        ];
    }

    /** @return array<string,mixed> */
    public function reset(string $confirmation, ?string $createdBy = null): array {
        if (trim($confirmation) !== self::CONFIRMATION) {
            throw new InvalidArgumentException('Type START FRESH to confirm the tagging reset.');
        }

        $before = $this->preview();
        $snapshot = $this->safety->createSnapshot(
            'Fresh tagging baseline ' . gmdate('Y-m-d H:i'),
            $createdBy
        );
        $runId = (int)$snapshot['id'];

        $this->db->beginTransaction();
        try {
            $ruleState = $this->db->query(
                "SELECT ta.id, ta.tag_id, ta.alias, ta.alias_normalized, ta.match_type, ta.direction, ta.active,
                    ta.origin, ta.confidence, ta.support_count, ta.last_matched_at, ta.created_at, ta.updated_at
                 FROM tag_aliases ta INNER JOIN tags tg ON tg.id = ta.tag_id
                 WHERE UPPER(TRIM(COALESCE(tg.name, ''))) <> 'IGNORE' ORDER BY ta.id"
            )->fetchAll(PDO::FETCH_ASSOC);
            $categoryState = $this->db->query(
                "SELECT ct.category_id, ct.tag_id FROM category_tags ct INNER JOIN tags tg ON tg.id = ct.tag_id
                 WHERE UPPER(TRIM(COALESCE(tg.name, ''))) <> 'IGNORE' ORDER BY ct.category_id, ct.tag_id"
            )->fetchAll(PDO::FETCH_ASSOC);
            $keywordState = $this->db->query(
                "SELECT id, keyword FROM tags
                 WHERE keyword IS NOT NULL AND keyword <> '' AND UPPER(TRIM(COALESCE(name, ''))) <> 'IGNORE' ORDER BY id"
            )->fetchAll(PDO::FETCH_ASSOC);

            $transactionsReset = $this->clearSnapshotClassifications($runId);
            $rulesRemoved = $this->removeRules();
            $categoryLinksCleared = $this->clearCategoryLinks();
            $keywordsCleared = $this->clearKeywords();

            $verification = $this->verifyReset($runId);
            if ($verification['eligible_classifications_remaining'] !== 0 || $verification['protected_changes'] !== 0) {
                throw new RuntimeException('The tagging reset did not reconcile and was cancelled.');
            }

            $summary = [
                'fresh_start' => [
                    'reset_at' => gmdate('c'),
                    'confirmation' => self::CONFIRMATION,
                    'before' => $before,
                    'result' => [
                        'transactions_reset' => $transactionsReset,
                        'rules_removed' => $rulesRemoved,
                        'category_links_cleared' => $categoryLinksCleared,
                        'keywords_cleared' => $keywordsCleared,
                    ],
                    'retained' => [
                        'canonical_tags' => $before['canonical_tags_retained'],
                        'protected_transfers' => $before['protected_transfers'],
                        'protected_ignored' => $before['protected_ignored'],
                    ],
                    'previous_rule_state' => $ruleState,
                    'previous_category_links' => $categoryState,
                    'previous_keywords' => $keywordState,
                ],
            ];
            $encoded = json_encode($summary, JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new RuntimeException('The reset audit record could not be encoded.');
            }
            $audit = $this->db->prepare('UPDATE tag_migration_runs SET cutover_summary = :summary WHERE id = :id');
            $audit->execute(['summary' => $encoded, 'id' => $runId]);

            $this->db->commit();
            Tag::clearMatchCaches();

            return [
                'snapshot_run_id' => $runId,
                'transactions_reset' => $transactionsReset,
                'rules_removed' => $rulesRemoved,
                'category_links_cleared' => $categoryLinksCleared,
                'keywords_cleared' => $keywordsCleared,
                'canonical_tags_retained' => (int)$before['canonical_tags_retained'],
                'protected_transfers' => (int)$before['protected_transfers'],
                'protected_ignored' => (int)$before['protected_ignored'],
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function clearSnapshotClassifications(int $runId): int {
        $stmt = $this->db->prepare(
            'UPDATE transactions SET tag_id = NULL, category_id = NULL, segment_id = NULL '
            . 'WHERE id IN (SELECT transaction_id FROM transaction_classification_snapshots WHERE run_id = :run_id AND eligible = 1) '
            . 'AND (tag_id IS NOT NULL OR category_id IS NOT NULL OR segment_id IS NOT NULL)'
        );
        $stmt->execute(['run_id' => $runId]);
        return $stmt->rowCount();
    }

    private function removeRules(): int {
        $stmt = $this->db->prepare(
            "DELETE FROM tag_aliases WHERE tag_id IN
             (SELECT id FROM tags WHERE UPPER(TRIM(COALESCE(name, ''))) <> 'IGNORE')"
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    private function clearCategoryLinks(): int {
        $stmt = $this->db->prepare(
            "DELETE FROM category_tags WHERE tag_id IN
             (SELECT id FROM tags WHERE UPPER(TRIM(COALESCE(name, ''))) <> 'IGNORE')"
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    private function clearKeywords(): int {
        $stmt = $this->db->prepare(
            "UPDATE tags SET keyword = NULL
             WHERE keyword IS NOT NULL AND keyword <> '' AND UPPER(TRIM(COALESCE(name, ''))) <> 'IGNORE'"
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    /** @return array{eligible_classifications_remaining:int,protected_changes:int} */
    private function verifyReset(int $runId): array {
        $eligible = $this->db->prepare(
            'SELECT COUNT(*) FROM transactions t INNER JOIN transaction_classification_snapshots s ON s.transaction_id = t.id '
            . 'WHERE s.run_id = :run_id AND s.eligible = 1 '
            . 'AND (t.tag_id IS NOT NULL OR t.category_id IS NOT NULL OR t.segment_id IS NOT NULL)'
        );
        $eligible->execute(['run_id' => $runId]);

        $protected = $this->db->prepare(
            'SELECT s.tag_id, s.category_id, s.segment_id, t.tag_id AS current_tag_id, '
            . 't.category_id AS current_category_id, t.segment_id AS current_segment_id '
            . 'FROM transaction_classification_snapshots s INNER JOIN transactions t ON t.id = s.transaction_id '
            . 'WHERE s.run_id = :run_id AND s.eligible = 0'
        );
        $protected->execute(['run_id' => $runId]);
        $protectedChanges = 0;
        foreach ($protected->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!$this->sameId($row['tag_id'], $row['current_tag_id'])
                || !$this->sameId($row['category_id'], $row['current_category_id'])
                || !$this->sameId($row['segment_id'], $row['current_segment_id'])) {
                $protectedChanges++;
            }
        }

        return [
            'eligible_classifications_remaining' => (int)$eligible->fetchColumn(),
            'protected_changes' => $protectedChanges,
        ];
    }

    private function sameId($left, $right): bool {
        if ($left === null || $right === null) return $left === null && $right === null;
        return (int)$left === (int)$right;
    }

}
?>
