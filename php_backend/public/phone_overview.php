<?php
// Read-only phone snapshot: shared financial authority, current calendar month.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/InstantDashboard.php';
require_once __DIR__ . '/../models/Account.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405); header('Allow: GET'); echo json_encode(['error'=>'Use GET to read your overview.']); exit;
}
try {
    $snapshot = InstantDashboard::getSnapshot(null, false);
    $accounts = Account::getSummaries();
    $accountTotals = ['bank'=>0.0,'cards'=>0.0];
    foreach ($accounts as $account) if (!(int)$account['closed']) $accountTotals[(int)$account['is_credit_card'] ? 'cards' : 'bank'] += (float)$account['balance'];
    echo json_encode([
        'period'=>$snapshot['period'],'headline'=>$snapshot['headline'], 'metrics'=>$snapshot['metrics'],
        'accounts'=>array_map(static fn($account)=>array_intersect_key($account,array_flip(['id','name','balance','ledger_balance_date','closed','is_credit_card'])), $accounts),'account_totals'=>$accountTotals,'pensions'=>$snapshot['pensions'],'mortgage'=>$snapshot['mortgage'],
        'top_categories'=>$snapshot['top_categories'],'recent'=>$snapshot['recent']
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    Log::write('Phone overview error: '.$e->getMessage(), 'ERROR');
    http_response_code(500); echo json_encode(['error'=>'The overview could not load. Please try again.']);
}
