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

            $direction = TagAlias::normalizeDirection((string)($row['direction'] ?? 'any'));
            $aliasKey = $direction . '|' . $aliasNormalized;
            if (!isset($aliasToCanonical[$aliasKey])) {
                $aliasToCanonical[$aliasKey] = $canonical;
            }
            $aliasesByTag[$tagId]['aliases'][$aliasKey] = $direction === 'any' ? $alias : ($alias . ' [' . $direction . ']');
        }

        $canonicalLines = [];
        $aliasLines = [];
        foreach ($aliasesByTag as $entry) {
            $name = $entry['name'];
            $canonicalLines[] = '- ' . $name;
            $aliases = array_values($entry['aliases']);
            if (empty($aliases)) continue;
            $aliases = array_slice($aliases, 0, $maxAliasesPerTag);
            $aliasLines[] = $name . ': ' . implode(', ', $aliases);
        }

        // Canonical names are the allowlist and must never be lost when the
        // much larger alias catalogue is trimmed for prompt size.
        $text = empty($canonicalLines)
            ? ''
            : "Allowed canonical tags:\n" . implode("\n", $canonicalLines);
        $truncated = false;
        if (!empty($aliasLines)) {
            $prefix = $text === '' ? '' : "\n\nAlias examples:\n";
            $available = max(0, $maxChars - strlen($text) - strlen($prefix));
            $included = [];
            foreach ($aliasLines as $line) {
                $candidate = implode("\n", array_merge($included, [$line]));
                if (strlen($candidate) > $available) {
                    $truncated = true;
                    break;
                }
                $included[] = $line;
            }
            if (!empty($included)) $text .= $prefix . implode("\n", $included);
            if (count($included) < count($aliasLines)) {
                $text .= "\n... (alias examples truncated; canonical allowlist above is complete)";
                $truncated = true;
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
    public static function resolveCanonicalTag(string $modelTag, array $canonicalByName, array $aliasToCanonical, ?float $amount = null): ?array {
        $normalized = self::normalizeText($modelTag);
        if ($normalized === '') {
            return null;
        }
        if (isset($canonicalByName[$normalized])) {
            $canonical = $canonicalByName[$normalized];
            return ['id' => (int)$canonical['id'], 'name' => (string)$canonical['name'], 'source' => 'canonical'];
        }

        $aliasNormalized = TagAlias::normalizeAlias($modelTag);
        if ($aliasNormalized !== '') {
            $direction = $amount === null || abs($amount) < 0.00001 ? 'any' : ($amount < 0 ? 'outgoing' : 'incoming');
            foreach ([$direction . '|' . $aliasNormalized, 'any|' . $aliasNormalized] as $aliasKey) {
                if (isset($aliasToCanonical[$aliasKey])) {
                    $canonical = $aliasToCanonical[$aliasKey];
                    return ['id' => (int)$canonical['id'], 'name' => (string)$canonical['name'], 'source' => 'alias'];
                }
            }
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
