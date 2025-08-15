<?php
// Handles OFX file uploads and imports transactions into the database.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/Account.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Log.php';
require_once __DIR__ . '/../models/Tag.php';
require_once __DIR__ . '/../models/CategoryTag.php';
require_once __DIR__ . '/../Database.php';

try {
    if (!isset($_FILES['ofx_files'])) {
        http_response_code(400);
        $msg = "No files uploaded.";
        Log::write($msg, 'ERROR');
        echo $msg;
        exit;
    }

    $files = $_FILES['ofx_files'];
    $messages = [];
    for ($i = 0; $i < count($files['name']); $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            $msg = "No file uploaded for entry " . ($i + 1) . ".";
            $messages[] = $msg;
            Log::write($msg, 'ERROR');
            continue;
        }

        $ofxData = file_get_contents($files['tmp_name'][$i]);
        if ($ofxData === false) {
            $msg = "Unable to read uploaded file " . $files['name'][$i] . ".";
            $messages[] = $msg;
            Log::write($msg, 'ERROR');
            continue;
        }

        // Normalise line endings and strip unprintable characters that may
        // cause issues for some financial software when parsing carriage
        // returns.
        $ofxData = str_replace(["\r\n", "\r"], "\n", $ofxData);
        $ofxData = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/', '', $ofxData);

        // Convert to UTF-8 if the file uses a different character set. On
        // systems without the mbstring extension fall back to iconv or assume
        // the data is already UTF-8 encoded.
        $encoding = 'UTF-8';
        if (function_exists('mb_detect_encoding')) {
            $detected = mb_detect_encoding($ofxData, 'UTF-8, ISO-8859-1, Windows-1252', true);
            if ($detected) {
                $encoding = $detected;
            }
        }
        if ($encoding !== 'UTF-8') {
            if (function_exists('mb_convert_encoding')) {
                $ofxData = mb_convert_encoding($ofxData, 'UTF-8', $encoding);
            } elseif (function_exists('iconv')) {
                $ofxData = iconv($encoding, 'UTF-8//TRANSLIT', $ofxData);
            }
        }

        // Extract account identifiers
        $sortCode = null;
        $accountNumber = null;
        $accountName = 'Default';
        if (preg_match('/<BANKACCTFROM>(.*?)<\/BANKACCTFROM>/is', $ofxData, $m)) {
            $block = $m[1];
            if (preg_match('/<BANKID>([^<]+)/i', $block, $sm)) {
                $sortCode = trim($sm[1]);
            }
            if (preg_match('/<ACCTID>([^<]+)/i', $block, $am)) {
                $accountNumber = trim($am[1]);
            }
            if (preg_match('/<ACCTNAME>([^<]+)/i', $block, $nm)) {
                $accountName = trim($nm[1]);
            }
        } elseif (preg_match('/<CCACCTFROM>(.*?)<\/CCACCTFROM>/is', $ofxData, $m)) {
            $block = $m[1];
            if (preg_match('/<ACCTID>([^<]+)/i', $block, $am)) {
                $accountNumber = trim($am[1]);
            }
            if (preg_match('/<ACCTNAME>([^<]+)/i', $block, $nm)) {
                $accountName = trim($nm[1]);
            }
        }

        if ($accountNumber === null) {
            $msg = "Missing account number in " . $files['name'][$i] . ".";
            $messages[] = $msg;
            Log::write($msg, 'ERROR');
            continue;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT id, name FROM accounts WHERE account_number = :num AND ((:sort IS NULL AND sort_code IS NULL) OR sort_code = :sort) LIMIT 1');
        $stmt->execute(['num' => $accountNumber, 'sort' => $sortCode]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($account) {
            $accountId = (int)$account['id'];
            if ($accountName && $account['name'] !== $accountName) {
                $upd = $db->prepare('UPDATE accounts SET name = :name WHERE id = :id');
                $upd->execute(['name' => $accountName, 'id' => $accountId]);
            }
        } else {
            $accountId = Account::create($accountName, $sortCode, $accountNumber);
        }

        // Update stored ledger balance if available
        if (preg_match('/<LEDGERBAL>.*?<BALAMT>([^<]+).*?<DTASOF>([^<]+)/is', $ofxData, $balMatch)) {
            $bal = (float)trim($balMatch[1]);
            $balDateStr = substr(trim($balMatch[2]), 0, 8);
            $balDate = date('Y-m-d', strtotime($balDateStr));
            Account::updateLedgerBalance($accountId, $bal, $balDate);
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
