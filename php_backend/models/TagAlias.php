<?php
// Model for mapping transaction descriptor aliases to canonical tags.
require_once __DIR__ . '/../Database.php';

class TagAlias {
    /**
     * Retrieve aliases with canonical tag names.
     */
    public static function all(): array {
        $db = Database::getConnection();
        $sql = 'SELECT ta.id, ta.tag_id, t.name AS tag_name, ta.alias, ta.match_type, ta.direction, ta.active, '
             . 'ta.origin, ta.confidence, ta.support_count, ta.last_matched_at '
             . 'FROM tag_aliases ta '
             . 'INNER JOIN tags t ON t.id = ta.tag_id '
             . 'ORDER BY ta.alias ASC';
        $stmt = $db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve one filtered page for remote table rendering.
     *
     * @return array{last_page:int,data:array,total:int}
     */
    public static function page(int $page, int $size, string $query = '', string $sortField = 'alias', string $sortDirection = 'asc'): array {
        $db = Database::getConnection();
        $page = max(1, $page);
        $size = max(10, min(100, $size));
        $allowedSorts = [
            'id' => 'ta.id',
            'alias' => 'ta.alias',
            'tag_name' => 't.name',
            'match_type' => 'ta.match_type',
            'direction' => 'ta.direction',
            'active' => 'ta.active',
            'support_count' => 'ta.support_count',
            'last_matched_at' => 'ta.last_matched_at',
        ];
        $orderBy = $allowedSorts[$sortField] ?? $allowedSorts['alias'];
        $direction = strtolower($sortDirection) === 'desc' ? 'DESC' : 'ASC';
        $where = '';
        $params = [];
        $query = trim($query);
        if ($query !== '') {
            $where = " WHERE LOWER(ta.alias) LIKE :query ESCAPE '!' OR LOWER(t.name) LIKE :query ESCAPE '!' OR LOWER(ta.match_type) LIKE :query ESCAPE '!' OR LOWER(ta.direction) LIKE :query ESCAPE '!'";
            $literalQuery = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], strtolower($query));
            $params['query'] = '%' . $literalQuery . '%';
        }

        $countSql = 'SELECT COUNT(*) FROM tag_aliases ta INNER JOIN tags t ON t.id = ta.tag_id' . $where;
        $count = $db->prepare($countSql);
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $lastPage = max(1, (int)ceil($total / $size));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $size;

        $sql = 'SELECT ta.id, ta.tag_id, t.name AS tag_name, ta.alias, ta.match_type, ta.direction, ta.active, '
             . 'ta.origin, ta.confidence, ta.support_count, ta.last_matched_at '
             . 'FROM tag_aliases ta '
             . 'INNER JOIN tags t ON t.id = ta.tag_id'
             . $where
             . ' ORDER BY ' . $orderBy . ' ' . $direction . ', ta.id ASC'
             . ' LIMIT ' . $size . ' OFFSET ' . $offset;
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return [
            'last_page' => $lastPage,
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
        ];
    }

    /**
     * Create a new alias mapping.
     */
    public static function create(int $tagId, string $alias, string $matchType = 'contains', bool $active = true, string $origin = 'manual', ?float $confidence = null, int $supportCount = 1, string $direction = 'any'): int {
        $db = Database::getConnection();
        $normalized = self::normalizeAlias($alias);
        $origin = self::normalizeOrigin($origin);
        $confidence = $confidence === null ? null : max(0, min(1, $confidence));
        $stmt = $db->prepare('INSERT INTO tag_aliases (tag_id, alias, alias_normalized, match_type, direction, active, origin, confidence, support_count) VALUES (:tag_id, :alias, :alias_normalized, :match_type, :direction, :active, :origin, :confidence, :support_count)');
        $stmt->execute([
            'tag_id' => $tagId,
            'alias' => trim($alias),
            'alias_normalized' => $normalized,
            'match_type' => self::normalizeMatchType($matchType),
            'direction' => self::normalizeDirection($direction),
            'active' => $active ? 1 : 0,
            'origin' => $origin,
            'confidence' => $confidence,
            'support_count' => max(0, $supportCount),
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * Update an existing alias mapping.
     */
    public static function update(int $id, int $tagId, string $alias, string $matchType = 'contains', bool $active = true, string $direction = 'any'): bool {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE tag_aliases SET tag_id = :tag_id, alias = :alias, alias_normalized = :alias_normalized, match_type = :match_type, direction = :direction, active = :active WHERE id = :id');
        return $stmt->execute([
            'id' => $id,
            'tag_id' => $tagId,
            'alias' => trim($alias),
            'alias_normalized' => self::normalizeAlias($alias),
            'match_type' => self::normalizeMatchType($matchType),
            'direction' => self::normalizeDirection($direction),
            'active' => $active ? 1 : 0,
        ]);
    }

    /**
     * Delete alias mapping.
     */
    public static function delete(int $id): bool {
        $db = Database::getConnection();
        $stmt = $db->prepare('DELETE FROM tag_aliases WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }

    /**
     * Return active aliases sorted by match precedence.
     */
    public static function activeMappings(): array {
        $db = Database::getConnection();
        $sql = 'SELECT ta.id, ta.tag_id, ta.alias, ta.alias_normalized, ta.match_type, ta.direction '
             . 'FROM tag_aliases ta '
             . 'INNER JOIN tags t ON t.id = ta.tag_id '
             . "WHERE ta.active = 1 AND t.status = 'active' "
             . 'ORDER BY CASE WHEN ta.direction = "any" THEN 1 ELSE 0 END, CASE WHEN ta.match_type = "exact" THEN 0 ELSE 1 END, LENGTH(ta.alias_normalized) DESC, ta.id ASC';
        $stmt = $db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Record which deterministic rules have actually been used. Match evidence
     * is accumulated once per tagging pass rather than writing for every
     * transaction.
     *
     * @param array<int,int> $matches Alias id => transaction match count.
     */
    public static function recordMatches(array $matches): void {
        if (empty($matches)) return;

        $db = Database::getConnection();
        $stmt = $db->prepare(
            'UPDATE tag_aliases SET support_count = support_count + :matches, '
            . 'last_matched_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        foreach ($matches as $id => $count) {
            $id = (int)$id;
            $count = (int)$count;
            if ($id <= 0 || $count <= 0) continue;
            $stmt->execute(['id' => $id, 'matches' => $count]);
        }
    }

    /**
     * Find active rules for other tags whose whole-word matching scope overlaps
     * a proposed rule. These are warnings, not confirmed classification errors.
     */
    public static function overlapWarnings(string $alias, int $tagId, string $direction = 'any', ?int $excludeId = null): array {
        $needle = self::normalizeMatchPhrase($alias);
        if ($needle === '') return [];

        $db = Database::getConnection();
        $sql = 'SELECT ta.id, ta.tag_id, ta.alias, ta.direction, t.name AS tag_name '
            . 'FROM tag_aliases ta INNER JOIN tags t ON t.id = ta.tag_id '
            . "WHERE ta.active = 1 AND t.status = 'active' AND ta.tag_id <> :tag_id";
        $stmt = $db->prepare($sql);
        $stmt->execute(['tag_id' => $tagId]);
        $direction = self::normalizeDirection($direction);
        $warnings = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($excludeId !== null && (int)$row['id'] === $excludeId) continue;
            $existingDirection = self::normalizeDirection((string)$row['direction']);
            if ($direction !== 'any' && $existingDirection !== 'any' && $direction !== $existingDirection) continue;
            $existing = self::normalizeMatchPhrase((string)$row['alias']);
            if ($existing === '') continue;
            $needlePadded = ' ' . $needle . ' ';
            $existingPadded = ' ' . $existing . ' ';
            if (strpos($needlePadded, $existingPadded) === false && strpos($existingPadded, $needlePadded) === false) continue;
            $warnings[] = [
                'id' => (int)$row['id'],
                'alias' => (string)$row['alias'],
                'tag_id' => (int)$row['tag_id'],
                'tag_name' => (string)$row['tag_name'],
                'direction' => $existingDirection,
            ];
            if (count($warnings) >= 8) break;
        }
        return $warnings;
    }

    /**
     * Check whether tag exists.
     */
    public static function tagExists(int $tagId): bool {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id FROM tags WHERE id = :id AND status = 'active' LIMIT 1");
        $stmt->execute(['id' => $tagId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Trim and lowercase alias for dedupe matching.
     */
    public static function normalizeAlias(string $alias): string {
        return strtolower(trim($alias));
    }

    private static function normalizeMatchPhrase(string $value): string {
        $value = trim($value);
        if (function_exists('mb_strtolower')) $value = mb_strtolower($value, 'UTF-8');
        else $value = strtolower($value);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
        return trim((string)preg_replace('/\s+/u', ' ', $value));
    }

    public static function normalizeDirection(string $direction): string {
        return in_array($direction, ['outgoing', 'incoming'], true) ? $direction : 'any';
    }

    private static function normalizeMatchType(string $matchType): string {
        return $matchType === 'exact' ? 'exact' : 'contains';
    }

    private static function normalizeOrigin(string $origin): string {
        return in_array($origin, ['system', 'manual', 'ai', 'legacy'], true) ? $origin : 'manual';
    }
}
?>
