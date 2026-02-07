<?php
// Model for mapping transaction descriptor aliases to canonical tags.
require_once __DIR__ . '/../Database.php';

class TagAlias {
    /**
     * Retrieve aliases with canonical tag names.
     */
    public static function all(): array {
        $db = Database::getConnection();
        $sql = 'SELECT ta.id, ta.tag_id, t.name AS tag_name, ta.alias, ta.match_type, ta.active, ta.created_at, ta.updated_at '
             . 'FROM tag_aliases ta '
             . 'INNER JOIN tags t ON t.id = ta.tag_id '
             . 'ORDER BY ta.alias ASC';
        $stmt = $db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Create a new alias mapping.
     */
    public static function create(int $tagId, string $alias, string $matchType = 'contains', bool $active = true): int {
        $db = Database::getConnection();
        $normalized = self::normalizeAlias($alias);
        $stmt = $db->prepare('INSERT INTO tag_aliases (tag_id, alias, alias_normalized, match_type, active) VALUES (:tag_id, :alias, :alias_normalized, :match_type, :active)');
        $stmt->execute([
            'tag_id' => $tagId,
            'alias' => trim($alias),
            'alias_normalized' => $normalized,
            'match_type' => self::normalizeMatchType($matchType),
            'active' => $active ? 1 : 0,
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * Update an existing alias mapping.
     */
    public static function update(int $id, int $tagId, string $alias, string $matchType = 'contains', bool $active = true): bool {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE tag_aliases SET tag_id = :tag_id, alias = :alias, alias_normalized = :alias_normalized, match_type = :match_type, active = :active WHERE id = :id');
        return $stmt->execute([
            'id' => $id,
            'tag_id' => $tagId,
            'alias' => trim($alias),
            'alias_normalized' => self::normalizeAlias($alias),
            'match_type' => self::normalizeMatchType($matchType),
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
        $sql = 'SELECT ta.tag_id, ta.alias, ta.alias_normalized, ta.match_type '
             . 'FROM tag_aliases ta '
             . 'INNER JOIN tags t ON t.id = ta.tag_id '
             . 'WHERE ta.active = 1 '
             . 'ORDER BY CASE WHEN ta.match_type = "exact" THEN 0 ELSE 1 END, ta.id ASC';
        $stmt = $db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check whether tag exists.
     */
    public static function tagExists(int $tagId): bool {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT id FROM tags WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $tagId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Trim and lowercase alias for dedupe matching.
     */
    public static function normalizeAlias(string $alias): string {
        return strtolower(trim($alias));
    }

    private static function normalizeMatchType(string $matchType): string {
        return $matchType === 'exact' ? 'exact' : 'contains';
    }
}
?>
