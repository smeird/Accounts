<?php
// Handles OFX file uploads and imports transactions into the database.
require_once __DIR__ . '/../models/Account.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Log.php';
require_once __DIR__ . '/../models/Tag.php';
require_once __DIR__ . '/../models/CategoryTag.php';
require_once __DIR__ . '/../Database.php';

try {
    if (!isset($_FILES['ofx_files'])) {
        http_response_code(400);
        echo "No files uploaded.";
        exit;
    }

    $files = $_FILES['ofx_files'];
    $messages = [];
    for ($i = 0; $i < count($files['name']); $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            $messages[] = "No file uploaded for entry " . ($i + 1) . ".";
            continue;
        }

        $ofxData = file_get_contents($files['tmp_name'][$i]);
        if ($ofxData === false) {
            $messages[] = "Unable to read uploaded file " . $files['name'][$i] . ".";
            continue;
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
            $memo = '';
            $type = null;
            if (preg_match('/<NAME>([^\r\n<]+)/i', $block, $dm)) {
                $desc = trim($dm[1]);
            }
            if (preg_match('/<MEMO>([^\r\n<]+)/i', $block, $mm)) {
                $memo = trim($mm[1]);
                if ($desc === '') {
                    $desc = $memo;
                }
            }
            if (preg_match('/<TRNTYPE>([^\r\n<]+)/i', $block, $tm)) {
                $type = strtoupper(trim($tm[1]));
            }
            $ofxId = null;
            if (preg_match('/<FITID>([^\r\n<]+)/i', $block, $om)) {
                $ofxId = trim($om[1]);
            }
            Transaction::create($accountId, $date, $amount, $desc, $memo, null, null, null, $ofxId, $type);
            $inserted++;
        }

        $tagged = Tag::applyToAccountTransactions($accountId);
        $categorised = CategoryTag::applyToAccountTransactions($accountId);

        $messages[] = "Inserted $inserted transactions for account $accountName. Tagged $tagged transactions. Categorised $categorised transactions.";
        Log::write("Inserted $inserted transactions for account $accountName; tagged $tagged transactions; categorised $categorised transactions");
    }

    echo implode("\n", $messages);
} catch (Exception $e) {
    http_response_code(500);
    $msg = 'Error: ' . $e->getMessage();
    Log::write($msg, 'ERROR');
    echo $msg;
}
?>
