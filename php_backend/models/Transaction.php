<?php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/Tag.php';

class Transaction {
    public static function create(int $account, string $date, float $amount, string $description, ?int $category = null, ?int $tag = null, ?int $group = null, ?string $ofx_id = null): int {
        if ($tag === null) {
            $tag = Tag::findMatch($description);
        }
        $db = Database::getConnection();
        $stmt = $db->prepare('INSERT INTO transactions (account_id, date, amount, description, category_id, tag_id, group_id, ofx_id) VALUES (:account, :date, :amount, :description, :category, :tag, :group, :ofx_id)');
        $stmt->execute([
            'account' => $account,
            'date' => $date,
            'amount' => $amount,
            'description' => $description,
            'category' => $category,
            'tag' => $tag,
            'group' => $group,
            'ofx_id' => $ofx_id
        ]);
        return (int)$db->lastInsertId();
    }


    public static function getByCategory(int $categoryId): array {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT date, amount, description FROM transactions WHERE category_id = :category');
        $stmt->execute(['category' => $categoryId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getByTag(int $tagId): array {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT date, amount, description FROM transactions WHERE tag_id = :tag');
        $stmt->execute(['tag' => $tagId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getByGroup(int $groupId): array {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT date, amount, description FROM transactions WHERE group_id = :grp');
        $stmt->execute(['grp' => $groupId]);

    public static function getByMonth(int $month, int $year): array {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM transactions WHERE MONTH(date) = :month AND YEAR(date) = :year ORDER BY date');
        $stmt->execute(['month' => $month, 'year' => $year]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
