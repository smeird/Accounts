<?php

// API endpoint that accepts a natural language query and returns the derived filters.
require_once __DIR__ . '/../auth.php';
require_api_auth();

require_once __DIR__ . '/../NaturalLanguageReportParser.php';

header('Content-Type: application/json');

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if ($q === '') {
    echo json_encode([]);
    exit;
}


echo json_encode(NaturalLanguageReportParser::parse($q));

?>
