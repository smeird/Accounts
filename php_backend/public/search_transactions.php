<?php
// API endpoint to search transactions across all fields.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/Log.php';
require_once __DIR__ . '/../models/Transaction.php';

header('Content-Type: application/json');

$value = $_GET['value'] ?? '';
$amount = isset($_GET['amount']) ? $_GET['amount'] : null;
$min = isset($_GET['min_amount']) ? $_GET['min_amount'] : null;
$max = isset($_GET['max_amount']) ? $_GET['max_amount'] : null;
$start = isset($_GET['start']) && $_GET['start'] !== '' ? (string)$_GET['start'] : null;
$end = isset($_GET['end']) && $_GET['end'] !== '' ? (string)$_GET['end'] : null;
$dimension = isset($_GET['dimension']) && $_GET['dimension'] !== '' ? (string)$_GET['dimension'] : null;
$dimensionId = isset($_GET['dimension_id']) && $_GET['dimension_id'] !== '' ? (int)$_GET['dimension_id'] : null;
$unclassified = isset($_GET['unclassified']) && $_GET['unclassified'] === '1';
$spendingOnly = isset($_GET['spending_only']) && $_GET['spending_only'] === '1';

if ($amount !== null) {
    $min = $max = $amount;
}

if ($value === '' && $min === null && $max === null && $dimension === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Search value or amount range is required']);
    exit;
}

if ($dimension !== null && !in_array($dimension, ['category', 'segment', 'group', 'tag'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported search dimension']);
    exit;
}
if ($dimension !== null && !$unclassified && ($dimensionId === null || $dimensionId <= 0)) {
    http_response_code(400);
    echo json_encode(['error' => 'A valid search dimension ID is required']);
    exit;
}

foreach (['start' => $start, 'end' => $end] as $label => $date) {
    if ($date !== null) {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            http_response_code(400);
            echo json_encode(['error' => "Invalid $label date"]);
            exit;
        }
    }
}
if ($start !== null && $end !== null && $start > $end) {
    http_response_code(400);
    echo json_encode(['error' => 'The start date must be before the end date']);
    exit;
}

try {
    $results = Transaction::search(
        $value,
        $min !== null ? (float)$min : null,
        $max !== null ? (float)$max : null,
        $start,
        $end,
        $dimension,
        $dimensionId,
        $unclassified,
        $spendingOnly
    );
    $total = 0.0;
    foreach ($results as $row) {
        if ($row['transfer_id'] === null) {
            $total += (float)$row['amount'];
        }
    }
    echo json_encode(['results' => $results, 'total' => $total]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
