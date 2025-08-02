<?php
require_once __DIR__ . '/../models/Account.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Log.php';
require_once __DIR__ . '/../models/Tag.php';
require_once __DIR__ . '/../models/CategoryTag.php';
require_once __DIR__ . '/../Database.php';

try {
    if (!isset($_FILES['ofx_file']) || $_FILES['ofx_file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo "No file uploaded.";
        exit;
    }

    $ofxData = file_get_contents($_FILES['ofx_file']['tmp_name']);
    if ($ofxData === false) {
        http_response_code(400);
        echo "Unable to read uploaded file.";
        exit;
    }

// try to get account name from <ACCTID> tag, fallback to 'Default'
$accountName = 'Default';
if (preg_match('/<ACCTID>([^\r\n<]+)/i', $ofxData, $m)) {
    $accountName = trim($m[1]);
}

$db = Database::getConnection();
$stmt = $db->prepare('SELECT id FROM accounts WHERE name = :name LIMIT 1');
$stmt->execute(['name' => $accountName]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);
if ($account) {
    $accountId = (int)$account['id'];
} else {
    $accountId = Account::create($accountName);
}

preg_match_all('/<STMTTRN>(.*?)<\/STMTTRN>/is', $ofxData, $matches);
$inserted = 0;
foreach ($matches[1] as $block) {
    if (preg_match('/<DTPOSTED>([^\r\n<]+)/i', $block, $m)) {
        $dateStr = substr(trim($m[1]), 0, 8); // YYYYMMDD
        $date = date('Y-m-d', strtotime($dateStr));
    } else {
        continue; // skip invalid entry
    }
    if (!preg_match('/<TRNAMT>([^\r\n<]+)/i', $block, $am)) {
        continue;
    }
    $amount = (float)trim($am[1]);
    $desc = '';
    if (preg_match('/<NAME>([^\r\n<]+)/i', $block, $dm)) {
        $desc = trim($dm[1]);
    } elseif (preg_match('/<MEMO>([^\r\n<]+)/i', $block, $dm)) {
        $desc = trim($dm[1]);
    }
    $ofxId = null;
    if (preg_match('/<FITID>([^\r\n<]+)/i', $block, $om)) {
        $ofxId = trim($om[1]);
    }
    Transaction::create($accountId, $date, $amount, $desc, null, null, null, $ofxId);
    $inserted++;
}

$tagged = Tag::applyToAccountTransactions($accountId);
$categorised = CategoryTag::applyToAccountTransactions($accountId);

    echo "Inserted $inserted transactions for account $accountName. Tagged $tagged transactions. Categorised $categorised transactions.";
    Log::write("Inserted $inserted transactions for account $accountName; tagged $tagged transactions; categorised $categorised transactions");
} catch (Exception $e) {
    http_response_code(500);
    $msg = 'Error: ' . $e->getMessage();
    Log::write($msg, 'ERROR');
    echo $msg;
}
?>
