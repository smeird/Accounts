<?php
// Pure helpers for safe AI suggestions that link existing tags to existing categories.
class AiCategoryTagger {
    public static function buildPrompt(array $categories, array $candidates): string {
        $prompt = "Assign each candidate financial tag to an existing category only when the match is clear. ";
        $prompt .= "Never create or rename categories or tags. A category_id must come from the supplied category list. ";
        $prompt .= "If uncertain, omit the tag or use category_id null with a low confidence. ";
        $prompt .= "Return one JSON object with an assignments array. Each item must be ";
        $prompt .= "{\"tag_id\":<integer>,\"category_id\":<integer|null>,\"confidence\":<0 to 1>,\"reason\":\"brief reason\"}.\n\n";
        $prompt .= "Existing categories and examples of tags already assigned to them:\n";
        foreach ($categories as $category) {
            $line = '- ' . (int)$category['id'] . ': ' . trim((string)$category['name']);
            $description = trim((string)($category['description'] ?? ''));
            if ($description !== '') {
                $line .= ' — ' . $description;
            }
            $examples = array_values(array_filter(array_map('trim', $category['assigned_tags'] ?? [])));
            if (!empty($examples)) {
                $line .= ' | existing tags: ' . implode(', ', array_slice($examples, 0, 8));
            }
            $prompt .= $line . "\n";
        }
        $prompt .= "\nUnassigned candidate tags:\n";
        foreach ($candidates as $candidate) {
            $line = '- ' . (int)$candidate['id'] . ': ' . trim((string)$candidate['name']);
            $metadata = [];
            foreach (['keyword', 'description'] as $field) {
                $value = trim((string)($candidate[$field] ?? ''));
                if ($value !== '') {
                    $metadata[] = $field . ': ' . $value;
                }
            }
            $metadata[] = 'transactions: ' . (int)($candidate['transactions'] ?? 0);
            $prompt .= $line . ' | ' . implode(' | ', $metadata) . "\n";
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

    /**
     * Accept only allowlisted IDs and high-confidence, one-per-tag suggestions.
     */
    public static function validateAssignments(
        array $response,
        array $candidateTagIds,
        array $categoryIds,
        float $threshold = 0.85
    ): array {
        $candidateSet = array_fill_keys(array_map('intval', $candidateTagIds), true);
        $categorySet = array_fill_keys(array_map('intval', $categoryIds), true);
        $items = isset($response['assignments']) && is_array($response['assignments'])
            ? $response['assignments']
            : [];
        $accepted = [];
        $rejected = [];
        $seen = [];

        foreach ($items as $item) {
            $tagId = (int)($item['tag_id'] ?? 0);
            $categoryId = isset($item['category_id']) ? (int)$item['category_id'] : 0;
            $confidence = isset($item['confidence']) && is_numeric($item['confidence'])
                ? (float)$item['confidence']
                : 0.0;
            $reason = trim((string)($item['reason'] ?? ''));
            if (!isset($candidateSet[$tagId])) {
                $rejected[] = ['tag_id' => $tagId, 'reason' => 'unknown_tag'];
                continue;
            }
            if (isset($seen[$tagId])) {
                $rejected[] = ['tag_id' => $tagId, 'reason' => 'duplicate_suggestion'];
                continue;
            }
            $seen[$tagId] = true;
            if (!isset($categorySet[$categoryId])) {
                $rejected[] = ['tag_id' => $tagId, 'reason' => 'unknown_or_unset_category'];
                continue;
            }
            if ($confidence < $threshold || $confidence > 1.0) {
                $rejected[] = ['tag_id' => $tagId, 'reason' => 'low_or_invalid_confidence'];
                continue;
            }
            $accepted[] = [
                'tag_id' => $tagId,
                'category_id' => $categoryId,
                'confidence' => round($confidence, 3),
                'reason' => substr($reason, 0, 240),
            ];
        }

        return ['accepted' => $accepted, 'rejected' => $rejected, 'suggested' => count($items)];
    }
}
?>
