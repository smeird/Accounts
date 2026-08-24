<?php
// Pure, deterministic transaction-pattern extraction for taxonomy discovery.
// It deliberately removes changing bank references before any text reaches AI.
class TagTaxonomyPattern {
    /** @return array{alias:string,alias_normalized:string,direction:string,signature:string} */
    public static function fromTransaction(string $description, ?string $memo, $amount): array {
        $descriptionTokens = self::meaningfulTokens($description);
        $memoTokens = self::meaningfulTokens((string)$memo);
        $tokens = $descriptionTokens;
        if (empty($tokens)) {
            $tokens = $memoTokens;
        }
        if (empty($tokens)) {
            $tokens = self::fallbackTokens(trim($description . ' ' . (string)$memo));
        }
        if (empty($tokens)) {
            $tokens = ['unclassified', 'transaction'];
        }

        $alias = self::truncate(implode(' ', array_slice($tokens, 0, 5)), 150);
        $normalized = self::normalize($alias);
        $direction = (float)$amount < 0 ? 'outgoing' : 'incoming';
        return [
            'alias' => $alias,
            'alias_normalized' => $normalized,
            'direction' => $direction,
            'signature' => hash('sha256', $direction . '|' . $normalized),
        ];
    }

    /** @return array<int,string> */
    private static function meaningfulTokens(string $value): array {
        $tokens = self::tokens($value);
        $boilerplate = array_fill_keys([
            'a', 'an', 'at', 'bacs', 'bill', 'card', 'cash', 'charge', 'contactless',
            'credit', 'debit', 'deposit', 'direct', 'faster', 'from', 'mobile', 'online',
            'order', 'payment', 'payments', 'paypal', 'pending', 'pos', 'purchase',
            'receipt', 'received', 'recurring', 'ref', 'reference', 'sent', 'square',
            'standing', 'stripe', 'sumup', 'the', 'to', 'transaction', 'transfer',
            'via', 'xfer', 'zettle'
        ], true);
        $out = [];
        foreach ($tokens as $token) {
            if (isset($boilerplate[$token]) || preg_match('/\d/u', $token)) {
                continue;
            }
            if (self::length($token) < 3 || self::length($token) > 60 || !preg_match('/\p{L}/u', $token)) {
                continue;
            }
            $out[] = $token;
        }
        return array_values(array_unique($out));
    }

    /** @return array<int,string> */
    private static function fallbackTokens(string $value): array {
        $out = [];
        foreach (self::tokens($value) as $token) {
            if (preg_match('/\d/u', $token) || self::length($token) < 3) {
                continue;
            }
            $out[] = $token;
        }
        return array_values(array_unique($out));
    }

    /** @return array<int,string> */
    private static function tokens(string $value): array {
        $normalized = self::normalize($value);
        return $normalized === '' ? [] : explode(' ', $normalized);
    }

    public static function normalize(string $value): string {
        $value = trim($value);
        if ($value === '') return '';
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    private static function truncate(string $value, int $length): string {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    }

    private static function length(string $value): int {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
?>
