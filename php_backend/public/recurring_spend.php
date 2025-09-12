<?php
// API endpoint to analyse recurring income and spending over the past year.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

try {
    $outgoings = Transaction::getRecurringSpend(false);
    $income = Transaction::getRecurringSpend(true);

    $outTotal = 0.0;
    $outNext = 0.0;
    foreach ($outgoings as $row) {
        $outTotal += (float)$row['total'];

        $outNext += (float)($row['last_amount'] ?? $row['average']);

    }

    $inTotal = 0.0;
    $inNext = 0.0;
    foreach ($income as $row) {
        $inTotal += (float)$row['total'];

        $inNext += (float)($row['last_amount'] ?? $row['average']);

    }

    echo json_encode([
        'outgoings' => ['results' => $outgoings, 'total' => $outTotal, 'next_month' => $outNext],
        'income' => ['results' => $income, 'total' => $inTotal, 'next_month' => $inNext]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Recurring spend error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
}
?>
