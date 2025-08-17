<?php
// List transactions tagged as IGNORE so they can be managed separately.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../models/Tag.php';

header('Content-Type: application/json');

try {
    $ignore = Tag::getIgnoreId();
    $db = Database::getConnection();
    $sql = 'SELECT t.id, t.date, t.amount, t.description, a.name AS account_name '
         . 'FROM transactions t JOIN accounts a ON t.account_id = a.id '
         . 'WHERE t.tag_id = :ignore ORDER BY t.date DESC, t.id DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute(['ignore' => $ignore]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
