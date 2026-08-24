<?php
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../services/FinancialWorkbookExportService.php';
require_once __DIR__ . '/../models/Log.php';

$start = (string)($_GET['start'] ?? date('Y-m-01'));
$end = (string)($_GET['end'] ?? date('Y-m-d'));
$temporary = null;

try {
    FinancialWorkbookExportService::validateRange($start, $end);
    $temporary = tempnam(sys_get_temp_dir(), 'accounts-excel-');
    if ($temporary === false) throw new RuntimeException('A temporary workbook could not be created.');

    $service = new FinancialWorkbookExportService();
    $data = $service->createWorkbook($start, $end, $temporary);
    $host = preg_replace('/[^A-Za-z0-9_-]/', '_', $_SERVER['HTTP_HOST'] ?? 'accounts');
    $filename = $host . '-financial-workbook-' . $start . '-to-' . $end . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($temporary));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    readfile($temporary);
    Log::write('Excel financial workbook exported for ' . $start . ' to ' . $end . ' with ' . count($data['transactions']) . ' transactions');
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    Log::write('Excel export error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'The Excel workbook could not be created.']);
} finally {
    if ($temporary !== null && is_file($temporary)) unlink($temporary);
}

