<?php
// Read model and guarded mutations for the permanent tagging workspace.
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../models/Tag.php';
require_once __DIR__ . '/../models/TagAlias.php';
require_once __DIR__ . '/../models/CategoryTag.php';
require_once __DIR__ . '/../models/Segment.php';
require_once __DIR__ . '/../models/Setting.php';
require_once __DIR__ . '/TaggingFreshStartService.php';

class TaggingWorkspaceService {
    private PDO $db;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?: Database::getConnection();
    }

    public function snapshot(int $inboxLimit = 100): array {
        $inboxLimit = max(10, min(250, $inboxLimit));
        return [
            'metrics' => $this->metrics(),
            'tags' => $this->activeTags(),
            'categories' => $this->categories(),
            'inbox' => $this->inbox($inboxLimit),
            'automation' => [
                'configured' => trim((string)(Setting::get('openai_api_token') ?? '')) !== '',
                'model' => (string)(Setting::get('ai_model') ?? 'gpt-5-nano'),
                'batch_size' => (int)(Setting::get('ai_tag_batch_size') ?? 100),
            ],
            'rebuild_history' => $this->latestRebuild(),
            'fresh_start' => (new TaggingFreshStartService($this->db))->preview(),
        ];
    }

    public function createTag(string $name, ?string $description, ?int $categoryId): array {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new InvalidArgumentException('Enter a canonical tag name of 100 characters or fewer.');
        }
        $activeId = Tag::getActiveIdByName($name);
        if ($activeId !== null) {
            throw new InvalidArgumentException('That canonical tag already exists. Use the existing tag instead.');
        }

        $existingId = Tag::getIdByName($name);
        $id = Tag::create($name, null, $description, 'manual');
        if ($categoryId !== null) CategoryTag::assign($categoryId, $id);
        return ['id' => $id, 'reactivated' => $existingId !== null];
    }

    public function updateTag(int $id, string $name, ?string $description, ?int $categoryId): array {
        $name = trim($name);
        if ($id <= 0 || $name === '' || mb_strlen($name) > 100) {
            throw new InvalidArgumentException('Choose an active tag and enter a valid name.');
        }
        $check = $this->db->prepare("SELECT origin, name_normalized, keyword FROM tags WHERE id = :id AND status = 'active'");
        $check->execute(['id' => $id]);
        $tag = $check->fetch(PDO::FETCH_ASSOC);
        if (!$tag) throw new InvalidArgumentException('The active tag could not be found.');
        if (($tag['origin'] ?? '') === 'system' || ($tag['name_normalized'] ?? '') === 'ignore') {
            throw new InvalidArgumentException('Protected system tags cannot be renamed.');
        }

        Tag::update($id, $name, $tag['keyword'] ?? null, $description);
        $assignment = CategoryTag::assign($categoryId, $id);
        Segment::applyToTransactions();
        return ['id' => $id, 'updated_transactions' => (int)$assignment['updated_transactions']];
    }

    public function retireTag(int $id): array {
        return Tag::retire($id);
    }

    public function mergeTag(int $sourceId, int $targetId): array {
        return Tag::merge($sourceId, $targetId);
    }

    public function resolveInbox(string $alias, int $tagId, string $direction = 'any', bool $confirmOverlap = false): array {
        $alias = trim($alias);
        $direction = TagAlias::normalizeDirection($direction);
        if ($alias === '' || mb_strlen($alias) > 150 || $tagId <= 0 || !TagAlias::tagExists($tagId)) {
            throw new InvalidArgumentException('Choose an existing canonical tag for this transaction pattern.');
        }

        $normalized = TagAlias::normalizeAlias($alias);
        $existing = $this->db->prepare(
            'SELECT id, tag_id FROM tag_aliases WHERE alias_normalized = :alias AND direction = :direction LIMIT 1'
        );
        $existing->execute(['alias' => $normalized, 'direction' => $direction]);
        $rule = $existing->fetch(PDO::FETCH_ASSOC);
        if ($rule && (int)$rule['tag_id'] !== $tagId) {
            throw new InvalidArgumentException('That transaction wording already belongs to another canonical tag. Review it in Rules.');
        }
        $overlaps = TagAlias::overlapWarnings($alias, $tagId, $direction, $rule ? (int)$rule['id'] : null);
        if (!$confirmOverlap && !empty($overlaps)) {
            return [
                'requires_confirmation' => true,
                'overlaps' => $overlaps,
                'tagged' => 0,
                'categorised' => 0,
                'segmented' => 0,
            ];
        }
        if ($rule) {
            $activate = $this->db->prepare("UPDATE tag_aliases SET active = 1, match_type = 'contains' WHERE id = :id");
            $activate->execute(['id' => (int)$rule['id']]);
        } else {
            TagAlias::create($tagId, $alias, 'contains', true, 'manual', null, 1, $direction);
        }

        Tag::clearMatchCaches();
        $tagged = Tag::applyToAllTransactions();
        $categorised = CategoryTag::applyToAllTransactions();
        $segmented = Segment::applyToTransactions();
        return ['tagged' => $tagged, 'categorised' => $categorised, 'segmented' => $segmented];
    }

    public function startFresh(string $confirmation, ?string $createdBy = null): array {
        return (new TaggingFreshStartService($this->db))->reset($confirmation, $createdBy);
    }

    private function metrics(): array {
        $transaction = $this->db->query(
            'SELECT COUNT(*) AS total, '
            . 'SUM(CASE WHEN tag_id IS NOT NULL THEN 1 ELSE 0 END) AS tagged, '
            . 'SUM(CASE WHEN tag_id IS NULL THEN 1 ELSE 0 END) AS untagged '
            . 'FROM transactions WHERE transfer_id IS NULL'
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $catalogue = $this->db->query(
            "SELECT COUNT(*) AS active_tags, "
            . "SUM(CASE WHEN NOT EXISTS (SELECT 1 FROM category_tags ct WHERE ct.tag_id = tags.id) THEN 1 ELSE 0 END) AS category_gaps, "
            . "SUM(CASE WHEN NOT EXISTS (SELECT 1 FROM transactions tx WHERE tx.tag_id = tags.id) THEN 1 ELSE 0 END) AS unused_tags "
            . "FROM tags WHERE status = 'active'"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $lengthFunction = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'LENGTH' : 'CHAR_LENGTH';
        $rules = $this->db->query(
            'SELECT COUNT(*) AS active_rules, '
            . 'SUM(CASE WHEN last_matched_at IS NOT NULL THEN 1 ELSE 0 END) AS observed_rules, '
            . 'SUM(CASE WHEN ' . $lengthFunction . '(alias_normalized) <= 4 THEN 1 ELSE 0 END) AS broad_rules '
            . 'FROM tag_aliases WHERE active = 1'
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $total = (int)($transaction['total'] ?? 0);
        $tagged = (int)($transaction['tagged'] ?? 0);
        return [
            'transactions' => $total,
            'tagged' => $tagged,
            'untagged' => (int)($transaction['untagged'] ?? 0),
            'coverage' => $total > 0 ? round(($tagged / $total) * 100, 1) : 100.0,
            'active_tags' => (int)($catalogue['active_tags'] ?? 0),
            'category_gaps' => (int)($catalogue['category_gaps'] ?? 0),
            'unused_tags' => (int)($catalogue['unused_tags'] ?? 0),
            'active_rules' => (int)($rules['active_rules'] ?? 0),
            'observed_rules' => (int)($rules['observed_rules'] ?? 0),
            'broad_rules' => (int)($rules['broad_rules'] ?? 0),
        ];
    }

    private function activeTags(): array {
        $sql = 'SELECT t.id, t.name, t.description, t.origin, c.id AS category_id, c.name AS category_name, '
            . 's.name AS segment_name, COALESCE(tx.transaction_count, 0) AS transaction_count, '
            . 'COALESCE(a.rule_count, 0) AS rule_count, a.last_rule_match '
            . 'FROM tags t LEFT JOIN category_tags ct ON ct.tag_id = t.id '
            . 'LEFT JOIN categories c ON c.id = ct.category_id LEFT JOIN segments s ON s.id = c.segment_id '
            . 'LEFT JOIN (SELECT tag_id, COUNT(*) AS transaction_count FROM transactions GROUP BY tag_id) tx ON tx.tag_id = t.id '
            . 'LEFT JOIN (SELECT tag_id, COUNT(*) AS rule_count, MAX(last_matched_at) AS last_rule_match '
            . 'FROM tag_aliases WHERE active = 1 GROUP BY tag_id) a ON a.tag_id = t.id '
            . "WHERE t.status = 'active' ORDER BY t.name ASC, t.id ASC";
        $rows = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        return array_map(function(array $row): array {
            $row['id'] = (int)$row['id'];
            $row['category_id'] = $row['category_id'] !== null ? (int)$row['category_id'] : null;
            $row['transaction_count'] = (int)$row['transaction_count'];
            $row['rule_count'] = (int)$row['rule_count'];
            $row['protected'] = ($row['origin'] ?? '') === 'system' || Tag::normalizeName((string)$row['name']) === 'ignore';
            return $row;
        }, $rows);
    }

    private function categories(): array {
        $rows = $this->db->query('SELECT id, name FROM categories ORDER BY name ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(function(array $row): array {
            return ['id' => (int)$row['id'], 'name' => (string)$row['name']];
        }, $rows);
    }

    private function inbox(int $limit): array {
        $sql = 'SELECT description, memo, '
            . "CASE WHEN amount < 0 THEN 'outgoing' WHEN amount > 0 THEN 'incoming' ELSE 'any' END AS direction, "
            . 'COUNT(*) AS transaction_count, SUM(amount) AS total_amount, MAX(date) AS latest_date '
            . 'FROM transactions WHERE tag_id IS NULL AND transfer_id IS NULL '
            . 'GROUP BY description, memo, direction ORDER BY transaction_count DESC, latest_date DESC LIMIT ' . $limit;
        $rows = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        return array_map(function(array $row): array {
            $row['transaction_count'] = (int)$row['transaction_count'];
            $row['total_amount'] = (float)$row['total_amount'];
            return $row;
        }, $rows);
    }

    private function latestRebuild(): ?array {
        $stmt = $this->db->query(
            'SELECT id, name, status, created_at, ready_at, applied_at, rolled_back_at, cutover_summary '
            . 'FROM tag_migration_runs ORDER BY id DESC LIMIT 1'
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $summary = json_decode((string)($row['cutover_summary'] ?? ''), true);
        $cleanup = is_array($summary) && isset($summary['legacy_cleanup']) && is_array($summary['legacy_cleanup'])
            ? $summary['legacy_cleanup']
            : null;
        $freshStart = is_array($summary) && isset($summary['fresh_start']) && is_array($summary['fresh_start'])
            ? $summary['fresh_start']
            : null;
        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'status' => (string)$row['status'],
            'created_at' => $row['created_at'],
            'applied_at' => $row['applied_at'],
            'rolled_back_at' => $row['rolled_back_at'],
            'cleanup_completed' => $cleanup !== null,
            'cleanup_at' => $cleanup['cleaned_at'] ?? null,
            'cleanup_metrics' => $cleanup['metrics'] ?? null,
            'fresh_start' => $freshStart,
        ];
    }
}
?>
