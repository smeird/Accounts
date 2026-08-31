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
$includeUnclassified = isset($_GET['include_unclassified']) && $_GET['include_unclassified'] === '1';
$spendingOnly = isset($_GET['spending_only']) && $_GET['spending_only'] === '1';
$direction = isset($_GET['direction']) && $_GET['direction'] !== '' ? (string)$_GET['direction'] : null;
$transferScope = isset($_GET['transfer_scope']) ? (string)$_GET['transfer_scope'] : 'include';
$ignoredScope = isset($_GET['ignored_scope']) ? (string)$_GET['ignored_scope'] : 'exclude';
$accountId = isset($_GET['account_id']) && $_GET['account_id'] !== '' ? (int)$_GET['account_id'] : null;
$dimensionIds = isset($_GET['dimension_ids']) && $_GET['dimension_ids'] !== '' ? explode(',', (string)$_GET['dimension_ids']) : [];
$transactionIds = isset($_GET['transaction_ids']) && $_GET['transaction_ids'] !== '' ? explode(',', (string)$_GET['transaction_ids']) : [];
$exactDescription = array_key_exists('description_exact', $_GET) ? (string)$_GET['description_exact'] : null;
$exactMemo = array_key_exists('memo_exact', $_GET) ? (string)$_GET['memo_exact'] : null;
$compareStart = isset($_GET['compare_start']) && $_GET['compare_start'] !== '' ? (string)$_GET['compare_start'] : null;
$compareEnd = isset($_GET['compare_end']) && $_GET['compare_end'] !== '' ? (string)$_GET['compare_end'] : null;
$all = isset($_GET['all']) && $_GET['all'] === '1';

if ($amount !== null) {
    $min = $max = $amount;
}

if ($value === '' && $min === null && $max === null && $dimension === null && $accountId === null
    && !$transactionIds && $exactDescription === null && $exactMemo === null && $direction === null
    && $start === null && $end === null && !$all) {
    http_response_code(400);
    echo json_encode(['error' => 'Search value or amount range is required']);
    exit;
}

if ($direction !== null && !in_array($direction, ['income', 'spending', 'all'], true)) {
    http_response_code(400); echo json_encode(['error' => 'Unsupported transaction direction']); exit;
}
if (!in_array($transferScope, ['include', 'exclude', 'only'], true)
    || !in_array($ignoredScope, ['include', 'exclude', 'only'], true)) {
    http_response_code(400); echo json_encode(['error' => 'Unsupported transaction inclusion scope']); exit;
}
if ($accountId !== null && $accountId <= 0) {
    http_response_code(400); echo json_encode(['error' => 'A valid account ID is required']); exit;
}
$normaliseIds = static function (array $values, int $limit, string $label): array {
    $ids = [];
    foreach ($values as $value) {
        if ($value === '' || !ctype_digit((string)$value) || (int)$value <= 0) {
            throw new InvalidArgumentException("Invalid $label ID");
        }
        $ids[(int)$value] = (int)$value;
    }
    if (count($ids) > $limit) throw new InvalidArgumentException("Too many $label IDs");
    return array_values($ids);
};
try {
    $dimensionIds = $normaliseIds($dimensionIds, 100, 'dimension');
    $transactionIds = $normaliseIds($transactionIds, 250, 'transaction');
} catch (InvalidArgumentException $e) {
    http_response_code(400); echo json_encode(['error' => $e->getMessage()]); exit;
}

if ($dimension !== null && !in_array($dimension, ['category', 'segment', 'group', 'tag'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported search dimension']);
    exit;
}
if ($dimension !== null && !$unclassified && !$dimensionIds && ($dimensionId === null || $dimensionId <= 0)) {
    http_response_code(400);
    echo json_encode(['error' => 'A valid search dimension ID is required']);
    exit;
}

foreach (['start' => $start, 'end' => $end, 'compare_start' => $compareStart, 'compare_end' => $compareEnd] as $label => $date) {
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
if (($compareStart === null) !== ($compareEnd === null) || ($compareStart !== null && $compareStart > $compareEnd)) {
    http_response_code(400); echo json_encode(['error' => 'A valid comparison date range is required']); exit;
}

try {
    $search = static function ($rangeStart, $rangeEnd) use ($value, $min, $max, $dimension, $dimensionId, $unclassified, $spendingOnly, $direction, $transferScope, $ignoredScope, $accountId, $dimensionIds, $transactionIds, $exactDescription, $exactMemo, $includeUnclassified) {
        return Transaction::search(
        $value,
        $min !== null ? (float)$min : null,
        $max !== null ? (float)$max : null,
        $rangeStart,
        $rangeEnd,
        $dimension,
        $dimensionId,
        $unclassified,
        $spendingOnly,
        $direction,
        $transferScope,
        $ignoredScope,
        $accountId,
        $dimensionIds,
        $transactionIds,
        $exactDescription,
        $exactMemo,
        $includeUnclassified
        );
    };
    $results = $search($start, $end);
    $total = 0.0;
    foreach ($results as $row) {
        if ($row['transfer_id'] === null) {
            $total += (float)$row['amount'];
        }
    }
    $payload = ['results' => $results, 'total' => $total];
    if ($compareStart !== null) {
        $comparison = $search($compareStart, $compareEnd);
        $comparisonTotal = 0.0;
        foreach ($comparison as $row) if ($row['transfer_id'] === null) $comparisonTotal += (float)$row['amount'];
        $payload['comparison_results'] = $comparison;
        $payload['comparison_total'] = $comparisonTotal;
        $payload['comparison'] = ['start' => $compareStart, 'end' => $compareEnd];
    }
    echo json_encode($payload);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
