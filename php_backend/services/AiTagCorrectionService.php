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
            'SELECT t.id, t.name, t.status, COUNT(tx.id) AS transaction_count '
            . 'FROM tags t LEFT JOIN transactions tx ON tx.tag_id = t.id '
            . "WHERE t.status = 'active' OR tx.id IS NOT NULL "
            . 'GROUP BY t.id, t.name, t.status ORDER BY transaction_count DESC, t.name ASC LIMIT 2500'
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'status' => (string)$row['status'],
            'transactions' => (int)$row['transaction_count'],
        ], $rows);
    }

    public static function buildPrompt(string $problem, array $tags): string {
        return "For a merchant instruction such as Virgin Media pymts are broadband costs, return mode=merchant_rule, source_tag_ids=[], match_terms=[the literal merchant wording], direction=outgoing (costs/payments), incoming (receipts), or any if unspecified. This mode replaces merchant rules and applies the replacement to matching transactions regardless of their current tag, including untagged rows. Never guess an incorrect source tag. For a whole-tag correction without merchant wording use mode=tag_only. "
            . "A person has described a transaction tagging error. Interpret only a tag correction. "
            . "Never propose changes to amounts, dates, descriptions, accounts, transfers, categories, segments or groups. "
            . "Choose source_tag_ids only from the supplied tag IDs. A deprecated tag may be a source when historical transactions still use it, but target_tag_id must always be an active tag. Prefer an existing active target_tag_id; if no suitable tag exists, set it to null and supply a short target_tag_name. "
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
            $tagMap[(int)$tag['id']] = [
                'name' => (string)$tag['name'],
                'status' => (string)($tag['status'] ?? 'active'),
            ];
        }
        if (($proposal['mode'] ?? '') === 'merchant_rule') return $this->createMerchantPlan($problem, $proposal, $tags);
        $sourceIds = array_values(array_unique(array_filter(array_map('intval', $proposal['source_tag_ids'] ?? []))));
        if (!$sourceIds || array_diff($sourceIds, array_keys($tagMap))) {
            throw new InvalidArgumentException('The AI could not identify a valid existing source tag.');
        }
        $targetId = isset($proposal['target_tag_id']) ? (int)$proposal['target_tag_id'] : 0;
        if ($targetId && !isset($tagMap[$targetId])) {
            throw new InvalidArgumentException('The AI selected a destination tag that does not exist.');
        }
        if ($targetId && $tagMap[$targetId]['status'] !== 'active') {
            throw new InvalidArgumentException('The AI selected a retired destination tag. Choose an active canonical tag instead.');
        }
        if ($targetId && in_array($targetId, $sourceIds, true)) {
            throw new InvalidArgumentException('The source and destination tags must be different.');
        }
        $targetName = $targetId ? $tagMap[$targetId]['name'] : trim((string)($proposal['target_tag_name'] ?? ''));
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
            'source_tags' => array_map(static fn(int $id): array => ['id' => $id, 'name' => $tagMap[$id]['name']], $sourceIds),
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
        if (($plan['mode'] ?? '') === 'merchant_rule') return $this->applyMerchantPlan($plan);
        $sourceIds = array_values(array_unique(array_map('intval', $plan['source_tag_ids'] ?? [])));
        $transactionIds = array_values(array_unique(array_map('intval', $plan['transaction_ids'] ?? [])));
        if (!$sourceIds || !$transactionIds) throw new InvalidArgumentException('The saved correction plan is incomplete.');

        $this->db->beginTransaction();
        try {
            $targetId = (int)($plan['target_tag_id'] ?? 0);
            if (!$targetId) $targetId = Tag::create((string)$plan['target_tag_name']);
            if (in_array($targetId, $sourceIds, true)) throw new RuntimeException('The destination tag now matches a source tag.');

            $target = $this->db->prepare("SELECT id FROM tags WHERE id = ? AND status = 'active'");
            $target->execute([$targetId]);
            if (!$target->fetchColumn()) throw new RuntimeException('The correction destination is no longer an active canonical tag.');
            $sourceMarks = implode(',', array_fill(0, count($sourceIds), '?'));
            $sourceCheck = $this->db->prepare("SELECT COUNT(*) FROM tags WHERE id IN ($sourceMarks) AND (origin = 'system' OR LOWER(TRIM(name)) = 'ignore')");
            $sourceCheck->execute($sourceIds);
            if ((int)$sourceCheck->fetchColumn() > 0) throw new RuntimeException('Protected system classifications cannot be corrected in bulk.');

            $idMarks = implode(',', array_fill(0, count($transactionIds), '?'));
            $sql = "UPDATE transactions SET tag_id = ? WHERE id IN ($idMarks) AND tag_id IN ($sourceMarks) AND transfer_id IS NULL AND tag_id NOT IN (SELECT id FROM tags WHERE LOWER(TRIM(name)) = 'ignore')";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(array_merge([$targetId], $transactionIds, $sourceIds));
            $updated = $stmt->rowCount();

            $movedAliases = $this->moveMatchingAliases($sourceIds, $targetId, (array)($plan['match_terms'] ?? []));

            $merged = [];
            if ($removeUnusedSources) {
                foreach ($sourceIds as $sourceId) {
                    $count = $this->db->prepare('SELECT COUNT(*) FROM transactions WHERE tag_id = ?');
                    $count->execute([$sourceId]);
                    if ((int)$count->fetchColumn() !== 0) continue;
                    $moveAliases = $this->db->prepare('UPDATE tag_aliases SET tag_id = ? WHERE tag_id = ?');
                    $moveAliases->execute([$targetId, $sourceId]);
                    $this->db->prepare('DELETE FROM category_tags WHERE tag_id = ?')->execute([$sourceId]);
                    $retire = $this->db->prepare("UPDATE tags SET status = 'merged', merged_into_tag_id = ?, keyword = NULL WHERE id = ?");
                    $retire->execute([$targetId, $sourceId]);
                    $merged[] = $sourceId;
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
                'removed_source_tag_ids' => [],
                'merged_source_tag_ids' => $merged,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function matchingTransactionIds(array $sourceIds, array $terms): array {
        $marks = implode(',', array_fill(0, count($sourceIds), '?'));
        $sql = "SELECT id FROM transactions WHERE tag_id IN ($marks) AND transfer_id IS NULL AND tag_id NOT IN (SELECT id FROM tags WHERE LOWER(TRIM(name)) = 'ignore')";
        $params = $sourceIds;
        if ($terms) {
            $clauses = [];
            foreach ($terms as $term) {
                $clauses[] = "LOWER(COALESCE(description, '') || ' ' || COALESCE(memo, '')) LIKE ? ESCAPE '!'";
                $params[] = '%' . self::escapeLike(self::normalise($term)) . '%';
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
                // A correction for a merchant phrase must not redirect a broader
                // rule that would affect unrelated future transactions.
                if ($value === $term) {
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

    private static function phrase(string $value): string {
        return trim((string)preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($value))));
    }

    private function merchantRows(array $terms, string $direction): array {
        $rows = $this->db->query("SELECT tx.* FROM transactions tx LEFT JOIN tags t ON t.id = tx.tag_id WHERE tx.transfer_id IS NULL AND (t.id IS NULL OR (LOWER(TRIM(t.name)) <> 'ignore' AND t.origin <> 'system')) ORDER BY tx.id")->fetchAll(PDO::FETCH_ASSOC);
        return array_values(array_filter($rows, static function ($row) use ($terms, $direction) {
            if ($direction === 'outgoing' && (float)$row['amount'] >= 0) return false;
            if ($direction === 'incoming' && (float)$row['amount'] <= 0) return false;
            $text = ' ' . self::phrase(Tag::buildMatchText($row['description'] ?? '', $row['memo'])) . ' ';
            foreach ($terms as $term) if (str_contains($text, ' ' . self::phrase($term) . ' ')) return true;
            return false;
        }));
    }

    private function merchantRules(array $terms, string $direction): array {
        $rules = [];
        foreach (TagAlias::all() as $rule) {
            if ($rule['direction'] !== 'any' && $direction !== 'any' && $rule['direction'] !== $direction) continue;
            foreach ($terms as $term) {
                $a = ' ' . self::phrase($rule['alias']) . ' ';
                $b = ' ' . self::phrase($term) . ' ';
                if (str_contains($a, $b) || str_contains($b, $a)) {
                    $rule['replace'] = self::phrase($rule['alias']) === self::phrase($term) && ($rule['direction'] === $direction || $rule['direction'] === 'any');
                    $rules[] = $rule; break;
                }
            }
        }
        return $rules;
    }

    private function createMerchantPlan(string $problem, array $proposal, array $tags): array {
        $terms = [];
        foreach ((array)($proposal['match_terms'] ?? []) as $term) {
            $term = trim((string)$term);
            if (mb_strlen($term) < 3 || mb_strlen($term) > 100 || !str_contains(' ' . self::phrase($problem) . ' ', ' ' . self::phrase($term) . ' ')) throw new InvalidArgumentException('Use merchant wording from your description.');
            $terms[] = $term;
        }
        $terms = array_values(array_unique($terms));
        if (!$terms || count($terms) > 5) throw new InvalidArgumentException('Specify one to five merchant phrases.');
        $direction = (string)($proposal['direction'] ?? 'any');
        if (!in_array($direction, ['any', 'outgoing', 'incoming'], true)) throw new InvalidArgumentException('Invalid rule direction.');
        $confidence = (float)($proposal['confidence'] ?? 0);
        if ($confidence < .75 || $confidence > 1) throw new InvalidArgumentException('Please describe the correction more specifically.');
        $targetId = (int)($proposal['target_tag_id'] ?? 0);
        $name = trim((string)($proposal['target_tag_name'] ?? ''));
        if ($targetId) {
            $allowed = array_column($tags, null, 'id');
            if (!isset($allowed[$targetId]) || $allowed[$targetId]['status'] !== 'active') throw new InvalidArgumentException('Choose an active destination tag.');
            $name = $allowed[$targetId]['name'];
        }
        if ($name === '' || mb_strlen($name) > 100 || strtolower($name) === 'ignore') throw new InvalidArgumentException('Specify a valid destination tag.');
        $existing = Tag::getIdByNormalizedName(Tag::normalizeName($name));
        if ($existing) {
            if (!Tag::isActiveId($existing)) throw new InvalidArgumentException('The destination name belongs to a retired tag. Choose an active tag.');
            $targetId = $existing;
        }
        $rows = $this->merchantRows($terms, $direction);
        if (count($rows) > 10000) throw new InvalidArgumentException('Narrow this correction to fewer than 10,000 transactions.');
        $rules = $this->merchantRules($terms, $direction);
        foreach ($rules as $rule) if ($rule['replace']) {
            $protected = $this->db->prepare("SELECT id FROM tags WHERE id = ? AND (origin = 'system' OR LOWER(TRIM(name)) = 'ignore')");
            $protected->execute([$rule['tag_id']]);
            if ($protected->fetchColumn()) throw new InvalidArgumentException('Protected system rules cannot be replaced.');
        }
        $warnings = ['An existing any-direction merchant rule is disabled; its opposite-direction behaviour is retained when needed.', 'Confirmed transfers, IGNORE and system classifications are excluded. Category, segment and group assignments stay unchanged.'];
        foreach ($rules as $rule) if (!$rule['replace'] && (int)$rule['active'] === 1 && (int)$rule['tag_id'] !== $targetId) $warnings[] = 'Overlapping rule retained: ' . $rule['alias'] . ' → ' . $rule['tag_name'] . '. More specific rules may still take precedence on future imports.';
        $sources = [];
        foreach ($rows as $row) if ($row['tag_id']) $sources[(int)$row['tag_id']] = true;
        $tagMap = array_column($tags, null, 'id');
        return ['mode'=>'merchant_rule', 'problem'=>$problem, 'summary'=>(string)($proposal['summary'] ?? 'Replace the merchant rule and apply it.'), 'source_tag_ids'=>array_keys($sources), 'source_tags'=>array_map(static fn($id)=>['id'=>$id, 'name'=>$tagMap[$id]['name'] ?? 'Existing tag'], array_keys($sources)), 'target_tag_id'=>$targetId ?: null, 'target_tag_name'=>$name, 'match_terms'=>$terms, 'direction'=>$direction, 'rules'=>$rules, 'transaction_ids'=>array_column($rows, 'id'), 'evidence_hash'=>hash('sha256', json_encode([$rows,$rules])), 'affected_count'=>count($rows), 'samples'=>array_slice($rows,0,12), 'confidence'=>$confidence, 'warnings'=>$warnings, 'created_at'=>time()];
    }

    private function applyMerchantPlan(array $plan): array {
        $this->db->beginTransaction();
        try {
            // Lock classifications and rules so the reviewed evidence cannot change during application.
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') $this->db->exec('LOCK TABLE transactions, tags, tag_aliases IN SHARE ROW EXCLUSIVE MODE');
            $rows = $this->merchantRows($plan['match_terms'], $plan['direction']);
            $rules = $this->merchantRules($plan['match_terms'], $plan['direction']);
            if (!hash_equals($plan['evidence_hash'], hash('sha256', json_encode([$rows,$rules])))) throw new InvalidArgumentException('Transactions or rules changed since preview. Analyse again.');
            $targetId = (int)$plan['target_tag_id'];
            if (!$targetId) {
                $existing = Tag::getIdByNormalizedName(Tag::normalizeName($plan['target_tag_name']));
                if ($existing && !Tag::isActiveId($existing)) throw new InvalidArgumentException('The destination tag was retired. Analyse again.');
                $targetId = Tag::create($plan['target_tag_name']);
            }
            if (!Tag::isActiveId($targetId)) throw new InvalidArgumentException('The destination tag was retired. Analyse again.');
            $ruleIds = [];
            foreach ($rules as $rule) if ($rule['replace']) {
                // Keep the opposite direction's existing behaviour when splitting an any-direction rule.
                if ($rule['direction'] === 'any' && $plan['direction'] !== 'any' && (int)$rule['active'] === 1) {
                    $opposite = $plan['direction'] === 'outgoing' ? 'incoming' : 'outgoing';
                    $find = $this->db->prepare('SELECT id FROM tag_aliases WHERE alias_normalized = ? AND direction = ?');
                    $find->execute([TagAlias::normalizeAlias($rule['alias']), $opposite]);
                    if (!$find->fetchColumn()) TagAlias::create((int)$rule['tag_id'], $rule['alias'], $rule['match_type'], true, 'manual', null, 0, $opposite);
                }
                $this->db->prepare('UPDATE tag_aliases SET active = 0 WHERE id = ?')->execute([$rule['id']]);
            }
            foreach ($plan['match_terms'] as $term) {
                $find = $this->db->prepare('SELECT id FROM tag_aliases WHERE alias_normalized = ? AND direction = ?');
                $find->execute([TagAlias::normalizeAlias($term), $plan['direction']]);
                $id = $find->fetchColumn();
                if ($id) TagAlias::update((int)$id, $targetId, $term, 'contains', true, $plan['direction']);
                else $id = TagAlias::create($targetId, $term, 'contains', true, 'manual', null, 0, $plan['direction']);
                $ruleIds[] = (int)$id;
            }
            $update = $this->db->prepare('UPDATE transactions SET tag_id = ? WHERE id = ?');
            $updated = 0;
            foreach ($rows as $row) {
                if ((int)$row['tag_id'] === $targetId) continue;
                $update->execute([$targetId,$row['id']]); $updated += $update->rowCount();
            }
            TagAlias::recordMatches([$ruleIds[0]=>count($rows)]);
            $this->db->commit(); Tag::clearMatchCaches();
            return ['updated'=>$updated, 'skipped'=>0, 'target_tag_id'=>$targetId, 'target_tag_name'=>$plan['target_tag_name'], 'merged_source_tag_ids'=>[], 'rule_ids'=>$ruleIds];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private static function normalise(string $value): string {
        return strtolower(trim((string)preg_replace('/\s+/u', ' ', $value)));
    }

    private static function escapeLike(string $value): string {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
?>
