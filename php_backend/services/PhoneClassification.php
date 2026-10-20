<?php
require_once __DIR__ . '/../models/Transaction.php';

/** Narrow phone edit: one ledger classification, no rule learning or taxonomy edits. */
class PhoneClassification {
    public static function revision(array $row): string {
        $values = [];
        foreach (['id','account_id','date','amount','description','memo','tag_id','category_id','segment_id','group_id','transfer_id'] as $key) {
            $values[$key] = isset($row[$key]) ? (string)$row[$key] : null;
        }
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    public static function get(int $id): array {
        $row = Transaction::get($id);
        if (!$row) throw new InvalidArgumentException('Transaction no longer exists.');
        $row['revision'] = self::revision($row);
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT origin FROM tags WHERE id = :id');
        $stmt->execute(['id' => $row['tag_id']]);
        $row['can_classify'] = $row['transfer_id'] === null
            && strtolower((string)$row['tag_name']) !== 'ignore' && $stmt->fetchColumn() !== 'system';
        // Phone details have no need for bank identifiers.
        unset($row['sort_code'], $row['account_number'], $row['ofx_id'], $row['bank_ofx_id']);
        return $row;
    }

    public static function save(int $id, int $tagId, string $revision, ?int $previewCategory, ?int $previewSegment): array {
        if ($id <= 0 || $tagId <= 0 || !preg_match('/^[a-f0-9]{64}$/D', $revision)) {
            throw new InvalidArgumentException('Choose an existing tag and reopen the transaction if needed.');
        }
        $db = Database::getConnection();
        $lock = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? ' FOR UPDATE' : '';
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT id FROM transactions WHERE id = :id' . $lock);
            $stmt->execute(['id' => $id]);
            $source = self::get($id);
            if (!hash_equals($source['revision'], $revision)) throw new DomainException('This transaction changed. Reopen it before saving; your selection has been kept.');
            if (!$source['can_classify']) throw new InvalidArgumentException('Transfers, ignored entries and system classifications are protected.');
            $stmt = $db->prepare('SELECT id, status, origin, name FROM tags WHERE id = :id' . $lock);
            $stmt->execute(['id' => $tagId]);
            $tag = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$tag || $tag['status'] !== 'active' || $tag['origin'] === 'system' || strtolower($tag['name']) === 'ignore') {
                throw new InvalidArgumentException('Choose an active existing tag.');
            }
            $stmt = $db->prepare('SELECT category_id FROM category_tags WHERE tag_id = :id ORDER BY category_id' . $lock);
            $stmt->execute(['id' => $tagId]);
            $categoryValue = $stmt->fetchColumn();
            $category = $categoryValue === false ? null : (int)$categoryValue;
            $segment = null;
            if ($category !== null) {
                $stmt = $db->prepare('SELECT segment_id FROM categories WHERE id = :id' . $lock);
                $stmt->execute(['id' => $category]);
                $value = $stmt->fetchColumn();
                $segment = $value === false || $value === null ? null : (int)$value;
            }
            if ($category !== $previewCategory || $segment !== $previewSegment) {
                throw new DomainException('That tag’s category changed. Search and select the tag again before saving.');
            }
            $stmt = $db->prepare('UPDATE transactions SET tag_id = :tag, category_id = :category, segment_id = :segment WHERE id = :id');
            $stmt->execute(['tag' => $tagId, 'category' => $category, 'segment' => $segment, 'id' => $id]);
            $result = self::get($id);
            $db->commit();
            return $result;
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }
}
