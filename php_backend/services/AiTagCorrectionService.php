<?php
// Builds and applies constrained AI-assisted tag corrections. The AI may
// describe a plan, but every database mutation is calculated and allowlisted
// by this service.
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../models/Tag.php';

class AiTagCorrectionService {
    private PDO $db;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?: Database::getConnection();
    }

    public function tagContext(): array {
        $rows = $this->db->query(
            'SELECT t.id, t.name, COUNT(tx.id) AS transaction_count '
            . 'FROM tags t LEFT JOIN transactions tx ON tx.tag_id = t.id '
            . 'GROUP BY t.id, t.name ORDER BY transaction_count DESC, t.name ASC LIMIT 2500'
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'transactions' => (int)$row['transaction_count'],
        ], $rows);
    }

    public static function buildPrompt(string $problem, array $tags): string {
        return "A person has described a transaction tagging error. Interpret only a tag correction. "
            . "Never propose changes to amounts, dates, descriptions, accounts, transfers, categories, segments or groups. "
            . "Choose source_tag_ids only from the supplied tag IDs. Prefer an existing target_tag_id; if no suitable tag exists, set it to null and supply a short target_tag_name. "
            . "Use match_terms only when the correction applies to transactions whose description or memo contains merchant wording written in the person's problem. Every match term must be a literal phrase from that problem. "
            . "Leave match_terms empty only when every transaction carrying the source tag should move. "
            . "Return one JSON object: {\"summary\":\"plain English interpretation\",\"source_tag_ids\":[1],\"target_tag_id\":2,\"target_tag_name\":\"name\",\"match_terms\":[\"literal phrase\"],\"confidence\":0.95,\"warnings\":[\"optional warning\"]}.\n\n"
            . "Person's problem:\n" . $problem . "\n\nAvailable tags (JSON):\n"
            . json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function createPlan(string $problem, array $proposal, array $tags): array {
        $problem = trim($problem);
        if ($problem === '' || mb_strlen($problem) > 2000) {
            throw new InvalidArgumentException('Describe the tagging problem in 2,000 characters or fewer.');
        }
        $tagMap = [];
        foreach ($tags as $tag) {
            $tagMap[(int)$tag['id']] = (string)$tag['name'];
        }
        $sourceIds = array_values(array_unique(array_filter(array_map('intval', $proposal['source_tag_ids'] ?? []))));
        if (!$sourceIds || array_diff($sourceIds, array_keys($tagMap))) {
            throw new InvalidArgumentException('The AI could not identify a valid existing source tag.');
        }
        $targetId = isset($proposal['target_tag_id']) ? (int)$proposal['target_tag_id'] : 0;
        if ($targetId && !isset($tagMap[$targetId])) {
            throw new InvalidArgumentException('The AI selected a destination tag that does not exist.');
        }
        if ($targetId && in_array($targetId, $sourceIds, true)) {
            throw new InvalidArgumentException('The source and destination tags must be different.');
        }
        $targetName = $targetId ? $tagMap[$targetId] : trim((string)($proposal['target_tag_name'] ?? ''));
        if ($targetName === '' || mb_strlen($targetName) > 100) {
            throw new InvalidArgumentException('The AI could not identify a valid destination tag.');
        }
        $confidence = is_numeric($proposal['confidence'] ?? null) ? (float)$proposal['confidence'] : 0.0;
        if ($confidence < 0.75 || $confidence > 1) {
            throw new InvalidArgumentException('The AI was not confident enough to prepare a safe correction. Please be more specific.');
        }

        $normalisedProblem = self::normalise($problem);
        $terms = [];
        foreach ((array)($proposal['match_terms'] ?? []) as $term) {
            $term = trim((string)$term);
            $normalised = self::normalise($term);
            if (mb_strlen($normalised) < 3 || mb_strlen($term) > 100 || strpos($normalisedProblem, $normalised) === false) {
                throw new InvalidArgumentException('The AI proposed matching wording that was not present in your description.');
            }
            $terms[$normalised] = $term;
            if (count($terms) >= 5) break;
        }

        $transactionIds = $this->matchingTransactionIds($sourceIds, array_values($terms));
        if (!$transactionIds) {
            throw new InvalidArgumentException('No transactions currently match that tag correction.');
        }
        if (count($transactionIds) > 10000) {
            throw new InvalidArgumentException('This correction would affect more than 10,000 transactions. Please describe a narrower problem.');
        }
        $samples = $this->transactionSamples($transactionIds, 12);
        $warnings = array_values(array_filter(array_map(static fn($v) => mb_substr(trim((string)$v), 0, 240), (array)($proposal['warnings'] ?? []))));
        if (!$terms) $warnings[] = 'No merchant wording was specified, so every transaction with the source tag is included.';
        if (!$targetId) $warnings[] = 'The destination tag does not exist yet and will be created when you apply the correction.';

        return [
            'problem' => $problem,
            'summary' => mb_substr(trim((string)($proposal['summary'] ?? 'Tag correction')), 0, 500),
            'source_tag_ids' => $sourceIds,
            'source_tags' => array_map(static fn(int $id): array => ['id' => $id, 'name' => $tagMap[$id]], $sourceIds),
            'target_tag_id' => $targetId ?: null,
            'target_tag_name' => $targetName,
            'match_terms' => array_values($terms),
            'transaction_ids' => $transactionIds,
            'affected_count' => count($transactionIds),
            'samples' => $samples,
            'confidence' => round($confidence, 3),
            'warnings' => array_values(array_unique($warnings)),
            'created_at' => time(),
        ];
    }

    public function applyPlan(array $plan, bool $removeUnusedSources = true): array {
        if ((int)($plan['created_at'] ?? 0) < time() - 900) {
            throw new InvalidArgumentException('This preview has expired. Analyse the problem again.');
        }
        $sourceIds = array_values(array_unique(array_map('intval', $plan['source_tag_ids'] ?? [])));
        $transactionIds = array_values(array_unique(array_map('intval', $plan['transaction_ids'] ?? [])));
        if (!$sourceIds || !$transactionIds) throw new InvalidArgumentException('The saved correction plan is incomplete.');

        $this->db->beginTransaction();
        try {
            $targetId = (int)($plan['target_tag_id'] ?? 0);
            if (!$targetId) $targetId = Tag::create((string)$plan['target_tag_name']);
            if (in_array($targetId, $sourceIds, true)) throw new RuntimeException('The destination tag now matches a source tag.');

            $idMarks = implode(',', array_fill(0, count($transactionIds), '?'));
            $sourceMarks = implode(',', array_fill(0, count($sourceIds), '?'));
            $sql = "UPDATE transactions SET tag_id = ? WHERE id IN ($idMarks) AND tag_id IN ($sourceMarks)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(array_merge([$targetId], $transactionIds, $sourceIds));
            $updated = $stmt->rowCount();

            $movedAliases = $this->moveMatchingAliases($sourceIds, $targetId, (array)($plan['match_terms'] ?? []));

            $removed = [];
            if ($removeUnusedSources) {
                foreach ($sourceIds as $sourceId) {
                    $count = $this->db->prepare('SELECT COUNT(*) FROM transactions WHERE tag_id = ?');
                    $count->execute([$sourceId]);
                    if ((int)$count->fetchColumn() !== 0) continue;
                    $moveAliases = $this->db->prepare('UPDATE tag_aliases SET tag_id = ? WHERE tag_id = ?');
                    $moveAliases->execute([$targetId, $sourceId]);
                    $this->db->prepare('DELETE FROM category_tags WHERE tag_id = ?')->execute([$sourceId]);
                    $this->db->prepare('DELETE FROM tags WHERE id = ?')->execute([$sourceId]);
                    $removed[] = $sourceId;
                }
            }
            $this->db->commit();
            Tag::clearMatchCaches();
            return [
                'updated' => $updated,
                'skipped' => count($transactionIds) - $updated,
                'target_tag_id' => $targetId,
                'target_tag_name' => (string)$plan['target_tag_name'],
                'moved_aliases' => $movedAliases,
                'removed_source_tag_ids' => $removed,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function matchingTransactionIds(array $sourceIds, array $terms): array {
        $marks = implode(',', array_fill(0, count($sourceIds), '?'));
        $sql = "SELECT id FROM transactions WHERE tag_id IN ($marks)";
        $params = $sourceIds;
        if ($terms) {
            $clauses = [];
            foreach ($terms as $term) {
                $clauses[] = "LOWER(COALESCE(description, '') || ' ' || COALESCE(memo, '')) LIKE ? ESCAPE '!'";
                $params[] = '%' . self::escapeLike(self::normalise($term)) . '%';
            }
            // MySQL uses CONCAT while SQLite uses ||.
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $clauses = array_fill(0, count($terms), "LOWER(CONCAT(COALESCE(description, ''), ' ', COALESCE(memo, ''))) LIKE ? ESCAPE '!'");
            }
            $sql .= ' AND (' . implode(' OR ', $clauses) . ')';
        }
        $sql .= ' ORDER BY id ASC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function transactionSamples(array $ids, int $limit): array {
        $sampleIds = array_slice($ids, 0, $limit);
        $marks = implode(',', array_fill(0, count($sampleIds), '?'));
        $stmt = $this->db->prepare("SELECT id, date, description, memo, amount FROM transactions WHERE id IN ($marks) ORDER BY date DESC, id DESC");
        $stmt->execute($sampleIds);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function moveMatchingAliases(array $sourceIds, int $targetId, array $terms): int {
        if (!$terms) return 0;
        $marks = implode(',', array_fill(0, count($sourceIds), '?'));
        $stmt = $this->db->prepare("SELECT id, alias_normalized FROM tag_aliases WHERE tag_id IN ($marks)");
        $stmt->execute($sourceIds);
        $aliasIds = [];
        $normalisedTerms = array_map([self::class, 'normalise'], $terms);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $alias) {
            $value = self::normalise((string)$alias['alias_normalized']);
            foreach ($normalisedTerms as $term) {
                if (strpos($value, $term) !== false || strpos($term, $value) !== false) {
                    $aliasIds[] = (int)$alias['id'];
                    break;
                }
            }
        }
        if (!$aliasIds) return 0;
        $idMarks = implode(',', array_fill(0, count($aliasIds), '?'));
        $move = $this->db->prepare("UPDATE tag_aliases SET tag_id = ? WHERE id IN ($idMarks)");
        $move->execute(array_merge([$targetId], $aliasIds));
        return $move->rowCount();
    }

    private static function normalise(string $value): string {
        return strtolower(trim((string)preg_replace('/\s+/u', ' ', $value)));
    }

    private static function escapeLike(string $value): string {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
?>
