<?php
// Conservative name suggestions only: never merge or classify automatically.
class TagCatalogueSuggestions {
    public static function nearest(string $name, array $tags, ?int $excludeId = null, int $limit = 5): array {
        $normal = self::normal($name);
        if ($normal === '') return [];
        $matches = [];
        foreach ($tags as $tag) {
            if ((int)$tag['id'] === $excludeId || !empty($tag['protected'])) continue;
            $candidate = self::normal($tag['name']);
            $left = explode(' ', $normal); $right = explode(' ', $candidate);
            $intersection = count(array_intersect($left, $right));
            $union = count(array_unique(array_merge($left, $right)));
            $score = max($union ? $intersection / $union : 0, 1 - levenshtein(substr($normal, 0, 200), substr($candidate, 0, 200)) / max(strlen($normal), strlen($candidate), 1));
            if ($score < 0.48) continue;
            $matches[] = ['id' => (int)$tag['id'], 'name' => $tag['name'], 'description' => $tag['description'] ?? '', 'category_id' => $tag['category_id'] ?? null, 'score' => round($score, 3)];
        }
        usort($matches, fn($a, $b) => ($b['score'] <=> $a['score']) ?: ($a['id'] <=> $b['id']));
        return array_slice($matches, 0, $limit);
    }

    private static function normal(string $value): string {
        $value = mb_strtolower(trim($value));
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value));
    }
}
