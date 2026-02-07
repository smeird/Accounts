<?php
// Helpers for AI tagging prompt context and canonical tag resolution.
require_once __DIR__ . '/models/TagAlias.php';

class AiTaggingPipeline {
    /**
     * Build canonical and alias lookup maps and prompt context text.
     *
     * @param array $rows Rows containing tag_id, tag_name and optional alias.
     * @return array{text:string,canonicalByName:array,aliasToCanonical:array,truncated:bool}
     */
    public static function buildAliasAwareTagContext(array $rows, int $maxAliasesPerTag = 5, int $maxChars = 2500): array {
        $canonicalByName = [];
        $aliasToCanonical = [];
        $aliasesByTag = [];

        foreach ($rows as $row) {
            $tagId = (int)($row['tag_id'] ?? 0);
            $tagName = trim((string)($row['tag_name'] ?? ''));
            if ($tagId <= 0 || $tagName === '') {
                continue;
            }

            $canonical = ['id' => $tagId, 'name' => $tagName];
            $canonicalByName[self::normalizeText($tagName)] = $canonical;

            if (!isset($aliasesByTag[$tagId])) {
                $aliasesByTag[$tagId] = ['name' => $tagName, 'aliases' => []];
            }

            $alias = trim((string)($row['alias'] ?? ''));
            if ($alias === '') {
                continue;
            }

            $aliasNormalized = TagAlias::normalizeAlias($alias);
            if ($aliasNormalized === '') {
                continue;
            }

            if (!isset($aliasToCanonical[$aliasNormalized])) {
                $aliasToCanonical[$aliasNormalized] = $canonical;
            }
            $aliasesByTag[$tagId]['aliases'][$aliasNormalized] = $alias;
        }

        $lines = [];
        foreach ($aliasesByTag as $entry) {
            $name = $entry['name'];
            $aliases = array_values($entry['aliases']);
            if (empty($aliases)) {
                continue;
            }
            $aliases = array_slice($aliases, 0, $maxAliasesPerTag);
            $lines[] = $name . ': ' . implode(', ', $aliases);
        }

        $text = '';
        $truncated = false;
        if (!empty($lines)) {
            $joined = implode("\n", $lines);
            if (strlen($joined) > $maxChars) {
                $text = substr($joined, 0, $maxChars);
                $lastBreak = strrpos($text, "\n");
                if ($lastBreak !== false) {
                    $text = substr($text, 0, $lastBreak);
                }
                $text .= "\n... (alias context truncated)";
                $truncated = true;
            } else {
                $text = $joined;
            }
        }

        return [
            'text' => $text,
            'canonicalByName' => $canonicalByName,
            'aliasToCanonical' => $aliasToCanonical,
            'truncated' => $truncated,
        ];
    }

    /**
     * Resolve model output to a canonical tag by exact tag name or alias.
     *
     * @return array|null ['id' => int, 'name' => string, 'source' => 'canonical'|'alias']
     */
    public static function resolveCanonicalTag(string $modelTag, array $canonicalByName, array $aliasToCanonical): ?array {
        $normalized = self::normalizeText($modelTag);
        if ($normalized === '') {
            return null;
        }
        if (isset($canonicalByName[$normalized])) {
            $canonical = $canonicalByName[$normalized];
            return ['id' => (int)$canonical['id'], 'name' => (string)$canonical['name'], 'source' => 'canonical'];
        }

        $aliasNormalized = TagAlias::normalizeAlias($modelTag);
        if ($aliasNormalized !== '' && isset($aliasToCanonical[$aliasNormalized])) {
            $canonical = $aliasToCanonical[$aliasNormalized];
            return ['id' => (int)$canonical['id'], 'name' => (string)$canonical['name'], 'source' => 'alias'];
        }

        return null;
    }

    private static function normalizeText(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
    }
}
?>
