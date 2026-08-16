<?php
// API endpoint for the comparison-aware Financial Trends dashboard.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/FinancialTrends.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

$start = isset($_GET['start']) ? trim((string)$_GET['start']) : '';
$end = isset($_GET['end']) ? trim((string)$_GET['end']) : '';
$dimension = isset($_GET['dimension']) ? trim((string)$_GET['dimension']) : 'category';
$comparisonStart = isset($_GET['comparison_start']) && $_GET['comparison_start'] !== ''
    ? trim((string)$_GET['comparison_start'])
    : null;
$comparisonEnd = isset($_GET['comparison_end']) && $_GET['comparison_end'] !== ''
    ? trim((string)$_GET['comparison_end'])
    : null;

try {
    if ($start === '' || $end === '') {
        throw new InvalidArgumentException('A start and end date are required');
    }
    echo json_encode(FinancialTrends::getSnapshot($start, $end, $dimension, $comparisonStart, $comparisonEnd));
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Financial Trends error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Unable to load financial trends']);
}
?>
