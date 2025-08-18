<?php
// List transactions tagged as IGNORE so they can be managed separately.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../models/Tag.php';

header('Content-Type: application/json');

try {
    Tag::getIgnoreId();
    $db = Database::getConnection();
    $sql = 'SELECT t.id, t.date, t.amount, t.description, a.name AS account_name '

         . 'FROM transactions t '
         . 'LEFT JOIN accounts a ON t.account_id = a.id '
         . 'LEFT JOIN tags tg ON t.tag_id = tg.id '
         . 'WHERE UPPER(tg.name) = :ignore ORDER BY t.date DESC, t.id DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute(['ignore' => 'IGNORE']);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
