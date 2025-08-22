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

use Ofx\TransactionType;
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
            $result = OfxParser::parse($ofxData);
            $statements = $result['statements'];
            $warningCounts = $result['warningCounts'];
            // Iterate over each parsed account separately.
        } catch (Exception $e) {
            $msg = 'Error parsing ' . $files['name'][$i] . ': ' . $e->getMessage();
            $messages[] = $msg;
            Log::write($msg, 'ERROR');
            continue;
        }

        foreach ($statements as $parsed) {
            $sortCode = $parsed['account']->sortCode;
            $accountNumber = $parsed['account']->number;
            $accountName = $parsed['account']->name;

            $db = Database::getConnection();
            // Match existing accounts using account number and sort code. When the
            // sort code is null (credit cards) prepared statements can behave
            // unpredictably if we rely on ":sort IS NULL" checks. Build the query
            // dynamically to ensure NULL is handled correctly and credit card
            // accounts are not mistaken for existing bank accounts.
            if ($sortCode === null) {
                $stmt = $db->prepare('SELECT id, name FROM accounts WHERE account_number = :num AND sort_code IS NULL LIMIT 1');
                $stmt->execute(['num' => $accountNumber]);
            } else {
                $stmt = $db->prepare('SELECT id, name FROM accounts WHERE account_number = :num AND sort_code = :sort LIMIT 1');
                $stmt->execute(['num' => $accountNumber, 'sort' => $sortCode]);
            }
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($account) {
                $accountId = (int)$account['id'];
            } else {
                $accountId = Account::create($accountName, $sortCode, $accountNumber);
            }

            if ($parsed['ledger']) {
                Account::updateLedgerBalance($accountId, $parsed['ledger']->balance, $parsed['ledger']->date);
            }


        $inserted = 0;
        $duplicates = [];
        $fileLedger = [];


            foreach ($parsed['transactions'] as $txn) {
                $amount = $txn->amount;
                $date = $txn->date;
                $desc = $txn->desc;
                $memo = $txn->memo;
                $type = $txn->type instanceof TransactionType ? $txn->type->value : (string)$txn->type;
                $bankId = $txn->bankId ? $txn->bankId : null;

                if ($txn->ref) {
                    $ref = substr($txn->ref, 0, Transaction::REF_MAX_LENGTH);
                    $memo .= ($memo === '' ? '' : ' ') . 'Ref:' . $ref;
                }
                if ($txn->check) {
                    $chk = substr($txn->check, 0, Transaction::CHECK_MAX_LENGTH);
                    $memo .= ($memo === '' ? '' : ' ') . 'Chk:' . $chk;
                }


            $substr = function_exists('mb_substr') ? 'mb_substr' : 'substr';
            $desc = $substr($desc, 0, Transaction::DESC_MAX_LENGTH);
            $memo = $memo === '' ? null : $substr($memo, 0, Transaction::MEMO_MAX_LENGTH);
            $bankId = $bankId === null ? null : $substr($bankId, 0, Transaction::ID_MAX_LENGTH);
            $type = $type === null ? null : $substr($type, 0, Transaction::TYPE_MAX_LENGTH);

            $amountStr = number_format($amount, 2, '.', '');
            $normalise = function (string $text): string {
                $text = strtoupper(trim($text));
                return preg_replace('/\s+/', ' ', $text);
            };
            $normDesc = $normalise($desc);
            $baseHash = sha1($accountId . $date . $amountStr . $normDesc);

            if ($bankId === null || $bankId === '') {
                $bankId = $baseHash;
            }

            $idKey = $bankId;
            if (isset($fileLedger[$idKey])) {
                $prev = $fileLedger[$idKey];
                if ($prev['amount'] != $amount || $prev['date'] !== $date || $prev['desc'] !== $desc || $prev['memo'] !== ($memo ?? '')) {
                    Log::write("FITID $idKey conflict within file", 'WARNING');
                }
                continue;
            }
            $fileLedger[$idKey] = ['amount' => $amount, 'date' => $date, 'desc' => $desc, 'memo' => $memo ?? ''];

            $syntheticId = $baseHash;

            $createdId = Transaction::create($accountId, $date, $amount, $desc, $memo, null, null, null, $syntheticId, $type, $bankId);
            if ($createdId === 0) {
                if ($bankId !== null) {
                    $duplicates[] = $bankId;

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
      }
  }

  echo implode("\n", $messages);
}
catch (Exception $e) {
    http_response_code(500);
    $msg = 'Error: ' . $e->getMessage();
    Log::write($msg, 'ERROR');
    echo $msg;
}
?>
