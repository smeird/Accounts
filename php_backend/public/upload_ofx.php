<?php
// Handles OFX file uploads and imports transactions into the database.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/Account.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Log.php';
require_once __DIR__ . '/../models/Tag.php';
require_once __DIR__ . '/../models/CategoryTag.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../OfxParser.php';

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
        $error = $files['error'][$i];
        if ($error !== UPLOAD_ERR_OK) {
            $errMap = [
                UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
                UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive specified in the HTML form.',
                UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.'
            ];
            $msg = ($errMap[$error] ?? 'Unknown upload error') . ' File: ' . $files['name'][$i];
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

        try {
            $parsed = OfxParser::parse($ofxData);
        } catch (Exception $e) {
            $msg = 'Error parsing ' . $files['name'][$i] . ': ' . $e->getMessage();
            $messages[] = $msg;
            Log::write($msg, 'ERROR');
            continue;
        }

        $sortCode = $parsed['account']['sort_code'];
        $accountNumber = $parsed['account']['number'];
        $accountName = $parsed['account']['name'];

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT id, name FROM accounts WHERE account_number = :num AND ((:sort IS NULL AND sort_code IS NULL) OR sort_code = :sort) LIMIT 1');
        $stmt->execute(['num' => $accountNumber, 'sort' => $sortCode]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($account) {
            $accountId = (int)$account['id'];
        } else {
            $accountId = Account::create($accountName, $sortCode, $accountNumber);
        }

        if ($parsed['ledger']) {
            Account::updateLedgerBalance($accountId, $parsed['ledger']['balance'], $parsed['ledger']['date']);
        }

        $inserted = 0;
        $duplicates = [];

        foreach ($matches[1] as $block) {
            if (preg_match('/<DTPOSTED>([^<]+)/i', $block, $m)) {
                $dateStr = substr(trim($m[1]), 0, 8); // YYYYMMDD
                $dt = DateTime::createFromFormat('Ymd', $dateStr);
                if (!$dt || $dt->format('Ymd') !== $dateStr) {
                    Log::write('Invalid date ' . $dateStr . ' in ' . $files['name'][$i], 'ERROR');
                    continue; // skip invalid entry
                }
                $date = $dt->format('Y-m-d');
            } else {
                Log::write('Missing DTPOSTED in transaction block', 'ERROR');
                continue; // skip invalid entry
            }
            if (!preg_match('/<TRNAMT>([^<]+)/i', $block, $am)) {
                Log::write('Missing TRNAMT in transaction block', 'ERROR');
                continue;
            }
            $amount = (float)trim($am[1]);
            $desc = '';
            $memo = '';
            $type = null;
            if (preg_match('/<NAME>([^<]+)/i', $block, $dm)) {
                $desc = trim($dm[1]);
            }
            if (preg_match('/<MEMO>([^<]+)/i', $block, $mm)) {
                $memo = trim($mm[1]);
                if ($desc === '') {
                    $desc = $memo;
                }
            }
            if (preg_match('/<TRNTYPE>([^<]+)/i', $block, $tm)) {
                $type = strtoupper(trim($tm[1]));
            }
            // Optional reference and cheque numbers with character limits
            $ref = '';
            $chk = '';
            if (preg_match('/<REFNUM>([^<]+)/i', $block, $rm)) {
                $ref = substr(trim($rm[1]), 0, 32);

                $memo .= ($memo === '' ? '' : ' ') . 'Ref:' . $ref;
            }
            if ($txn['check']) {
                $chk = substr($txn['check'], 0, 20);
                $memo .= ($memo === '' ? '' : ' ') . 'Chk:' . $chk;
            }

            $substr = function_exists('mb_substr') ? 'mb_substr' : 'substr';
            $desc = $substr($desc, 0, 255);
            $memo = $memo === '' ? null : $substr($memo, 0, 255);
            $bankId = $bankId === null ? null : $substr($bankId, 0, 255);
            $type = $type === null ? null : $substr($type, 0, 50);


            // Generate synthetic ID incorporating optional reference data

            $amountStr = number_format($amount, 2, '.', '');
            $normalise = function (string $text): string {
                $text = strtoupper(trim($text));
                return preg_replace('/\s+/', ' ', $text);
            };
            $normDesc = $normalise($desc);


            $createdId = Transaction::create($accountId, $date, $amount, $desc, $memo, null, null, null, $syntheticId, $type, $bankId);
            if ($createdId === 0) {
                if ($bankId !== null) {
                    $duplicates[] = $bankId;
                }
                continue;
            }

            $inserted++;
        }

        $tagged = Tag::applyToAccountTransactions($accountId);
        $categorised = CategoryTag::applyToAccountTransactions($accountId);

        $msg = "Inserted $inserted transactions for account $accountName. Tagged $tagged transactions. Categorised $categorised transactions.";
        if (!empty($duplicates)) {
            $msg .= " Skipped duplicates with FITID(s): " . implode(', ', $duplicates) . '.';
        }
        $messages[] = $msg;
        Log::write($msg);
    }

    echo implode("\n", $messages);
} catch (Exception $e) {
    http_response_code(500);
    $msg = 'Error: ' . $e->getMessage();
    Log::write($msg, 'ERROR');
    echo $msg;
}
?>
