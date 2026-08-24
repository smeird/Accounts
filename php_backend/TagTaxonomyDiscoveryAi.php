<?php
// Pure prompt and response validation for review-only taxonomy discovery.
require_once __DIR__ . '/TagTaxonomyPattern.php';

class TagTaxonomyDiscoveryAi {
    public static function buildPrompt(array $patterns, array $categories, array $approved, array $rejectedNames): string {
        $prompt = "Design a compact, reusable financial tag taxonomy from transaction patterns. ";
        $prompt .= "A tag describes a durable transaction type, never an individual transaction, reference number, date, location, or one-off merchant spelling. ";
        $prompt .= "Reuse the same canonical tag for patterns with the same financial purpose. Prefer an already approved or staged canonical tag when it fits. ";
        $prompt .= "Do not return IGNORE and do not classify transfers. Category IDs are optional but, when supplied, must come from the allowlist. ";
        $prompt .= "Return one JSON object with an assignments array. Every item must be ";
        $prompt .= "{\"pattern_id\":<integer>,\"canonical_tag\":\"short broad tag\",\"description\":\"durable definition\",\"category_id\":<integer|null>,\"confidence\":<0 to 1>,\"reason\":\"brief reason\"}.\n\n";

        if (!empty($approved)) {
            $prompt .= "Canonical tags already staged (reuse whenever suitable):\n";
            foreach ($approved as $item) {
                $prompt .= '- ' . trim((string)$item['canonical_name']);
                if (!empty($item['description'])) $prompt .= ' — ' . trim((string)$item['description']);
                $prompt .= "\n";
            }
            $prompt .= "\n";
        }
        if (!empty($rejectedNames)) {
            $prompt .= 'Rejected canonical names (do not propose again): ' . implode(', ', array_slice($rejectedNames, 0, 100)) . "\n\n";
        }

        $prompt .= "Existing category allowlist:\n";
        foreach ($categories as $category) {
            $prompt .= '- ' . (int)$category['id'] . ': ' . trim((string)$category['name']);
            if (!empty($category['description'])) $prompt .= ' — ' . trim((string)$category['description']);
            $prompt .= "\n";
        }

        $prompt .= "\nPatterns to classify:\n";
        foreach ($patterns as $pattern) {
            $prompt .= '- ' . (int)$pattern['id'] . ': ' . (string)$pattern['direction'] . ' | alias: ' . trim((string)$pattern['alias']);
            $prompt .= ' | sample: ' . trim((string)$pattern['sample_description']);
            if (!empty($pattern['sample_memo'])) $prompt .= ' / ' . trim((string)$pattern['sample_memo']);
            $prompt .= ' | transactions: ' . (int)$pattern['transaction_count'];
            $prompt .= ' | current tags: ' . trim((string)($pattern['current_tags'] ?? 'none')) . "\n";
        }
        return $prompt;
    }

    public static function extractOutputText(array $response): string {
        $content = trim((string)($response['output_text'] ?? ''));
        if ($content === '' && isset($response['output']) && is_array($response['output'])) {
            foreach ($response['output'] as $output) {
                foreach (($output['content'] ?? []) as $part) {
                    if (!empty($part['text'])) {
                        $content = trim((string)$part['text']);
                        break 2;
                    }
                }
            }
        }
        if (substr($content, 0, 3) === '```') {
            $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
            $content = preg_replace('/```\s*$/', '', $content);
            $content = trim($content);
        }
        return $content;
    }

    /** @return array{accepted:array,rejected:array,suggested:int} */
    public static function validate(array $response, array $patternIds, array $categoryIds, array $rejectedNames = []): array {
        $allowedPatterns = array_fill_keys(array_map('intval', $patternIds), true);
        $allowedCategories = array_fill_keys(array_map('intval', $categoryIds), true);
        $rejectedSet = [];
        foreach ($rejectedNames as $name) {
            $rejectedSet[TagTaxonomyPattern::normalize((string)$name)] = true;
        }
        $items = isset($response['assignments']) && is_array($response['assignments']) ? $response['assignments'] : [];
        $accepted = [];
        $rejected = [];
        $seen = [];
        foreach ($items as $item) {
            $patternId = (int)($item['pattern_id'] ?? 0);
            $name = trim((string)($item['canonical_tag'] ?? ''));
            $normalized = TagTaxonomyPattern::normalize($name);
            $categoryId = isset($item['category_id']) && $item['category_id'] !== null ? (int)$item['category_id'] : null;
            $confidence = isset($item['confidence']) && is_numeric($item['confidence']) ? (float)$item['confidence'] : -1;
            $reason = '';
            if (!isset($allowedPatterns[$patternId])) $reason = 'unknown_pattern';
            elseif (isset($seen[$patternId])) $reason = 'duplicate_pattern';
            elseif ($normalized === '' || self::length($name) > 100) $reason = 'invalid_canonical_name';
            elseif ($normalized === 'ignore' || isset($rejectedSet[$normalized])) $reason = 'protected_or_rejected_name';
            elseif ($categoryId !== null && !isset($allowedCategories[$categoryId])) $reason = 'unknown_category';
            elseif ($confidence < 0 || $confidence > 1) $reason = 'invalid_confidence';
            if ($reason !== '') {
                $rejected[] = ['pattern_id' => $patternId, 'reason' => $reason];
                continue;
            }
            $seen[$patternId] = true;
            $accepted[] = [
                'pattern_id' => $patternId,
                'canonical_name' => self::truncate($name, 100),
                'canonical_name_normalized' => $normalized,
                'description' => self::truncate(trim((string)($item['description'] ?? '')), 1000),
                'category_id' => $categoryId,
                'confidence' => round($confidence, 4),
                'rationale' => self::truncate(trim((string)($item['reason'] ?? '')), 500),
            ];
        }
        return ['accepted' => $accepted, 'rejected' => $rejected, 'suggested' => count($items)];
    }

    private static function truncate(string $value, int $length): string {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    }

    private static function length(string $value): int {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
?>
