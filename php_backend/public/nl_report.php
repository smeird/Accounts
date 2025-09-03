<?php
// API endpoint that accepts a natural language query and returns filtered transactions.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../NaturalLanguageReportParser.php';

header('Content-Type: application/json');

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if ($q === '') {
    echo json_encode([]);
    exit;
}

$filters = NaturalLanguageReportParser::parse($q);

echo json_encode(Transaction::filter(
    $filters['category'] ?? null,
    $filters['tag'] ?? null,
    $filters['group'] ?? null,
    $filters['segment'] ?? null,
    $filters['text'] ?? null,
    $filters['start'] ?? null,
    $filters['end'] ?? null
));
?>
