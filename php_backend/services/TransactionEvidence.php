<?php
/** Short-lived, session-owned evidence. Only opaque tokens reach navigation URLs. */
class TransactionEvidence {
    public const PAGE_SIZE = 250;
    public static function create(array $ids, string $purpose = 'import'): ?string {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
        if (!$ids) return null;
        self::prune();
        // Bound session storage; an oversized import remains successful but cannot offer scoped review.
        if (count($ids) > 100000) return null;
        while (count($_SESSION['transaction_evidence'] ?? []) >= 5) array_shift($_SESSION['transaction_evidence']);
        $token = bin2hex(random_bytes(24));
        $_SESSION['transaction_evidence'][$token] = ['ids'=>$ids,'expires'=>time()+7200,'owner'=>$_SESSION['user_id'] ?? null,'purpose'=>$purpose];
        return $token;
    }
    public static function page(string $token, int $page = 1, ?string $purpose = null): array {
        self::prune();
        $entry = $_SESSION['transaction_evidence'][$token] ?? null;
        if (!$entry || ($purpose !== null && $entry['purpose'] !== $purpose) || $entry['owner'] !== ($_SESSION['user_id'] ?? null) || !preg_match('/^[a-f0-9]{48}$/D', $token)) throw new InvalidArgumentException('This review has expired. Return to the transaction list to start another review.');
        $pages = (int)ceil(count($entry['ids']) / self::PAGE_SIZE);
        if ($page < 1 || $page > $pages) throw new InvalidArgumentException('Invalid review page.');
        return ['ids'=>array_slice($entry['ids'],($page-1)*self::PAGE_SIZE,self::PAGE_SIZE),'page'=>$page,'pages'=>$pages,'total'=>count($entry['ids'])];
    }
    private static function prune(): void {
        foreach ($_SESSION['transaction_evidence'] ?? [] as $token=>$entry) if ($entry['expires'] < time()) unset($_SESSION['transaction_evidence'][$token]);
    }
}
