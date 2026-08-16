<?php
// Imports OFX statement contents and returns structured, per-file results.
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../OfxParser.php';
require_once __DIR__ . '/../models/Account.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Tag.php';
require_once __DIR__ . '/../models/CategoryTag.php';
require_once __DIR__ . '/../models/Log.php';

class OfxImportService {
    private PDO $db;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? Database::getConnection();
    }

    /**
     * Import one statement file as an atomic unit.
     *
     * @return array<string,mixed>
     */
    public function importContent(string $filename, string $contents): array {
        $filename = basename($filename) ?: 'statement.ofx';
        if (trim($contents) === '') {
            return $this->errorResult($filename, 'The statement file is empty.');
        }

        try {
            $parsed = OfxParser::parse($this->normaliseEncoding($contents));
        } catch (Throwable $e) {
            $message = 'This file could not be parsed: ' . $e->getMessage();
            Log::write("OFX import parse error for $filename: " . $e->getMessage(), 'ERROR');
            return $this->errorResult($filename, $message);
        }

        $warningCounts = is_array($parsed['warningCounts'] ?? null) ? $parsed['warningCounts'] : [];
        $warningTotal = array_sum(array_map('intval', $warningCounts));
        $accountResults = [];
        $seenIdentities = [];

        $transactionStarted = false;
        try {
            $this->db->beginTransaction();
            $transactionStarted = true;
            foreach ($parsed['statements'] as $statement) {
                $accountId = $this->findOrCreateAccount($statement['account']);
                $accountName = trim((string)$statement['account']->name) ?: 'Unnamed account';

                if (!isset($accountResults[$accountId])) {
                    $accountResults[$accountId] = [
                        'account_id' => $accountId,
                        'account_name' => $accountName,
                        'inserted' => 0,
                        'duplicates' => 0,
                        'tagged' => 0,
                        'categorised' => 0,
                        'inserted_ids' => [],
                        'balance_status' => 'not_supplied',
                    ];
                }

                foreach ($statement['transactions'] as $transaction) {
                    $prepared = $this->prepareTransaction($accountId, $transaction);
                    $identityKey = $prepared['identity_key'];
                    if (isset($seenIdentities[$identityKey])) {
                        $accountResults[$accountId]['duplicates']++;
                        if ($seenIdentities[$identityKey] !== $prepared['fingerprint']) {
                            $warningCounts['identity'] = ($warningCounts['identity'] ?? 0) + 1;
                            $warningTotal++;
                            Log::write("Conflicting OFX identity $identityKey within $filename", 'WARNING');
                        }
                        continue;
                    }
                    $seenIdentities[$identityKey] = $prepared['fingerprint'];

                    $createdId = Transaction::create(
                        $accountId,
                        $prepared['date'],
                        $prepared['amount'],
                        $prepared['description'],
                        $prepared['memo'],
                        null,
                        null,
                        null,
                        $prepared['ofx_id'],
                        $prepared['type'],
                        $prepared['bank_id']
                    );

                    if ($createdId === 0) {
                        $accountResults[$accountId]['duplicates']++;
                    } else {
                        $accountResults[$accountId]['inserted']++;
                        $accountResults[$accountId]['inserted_ids'][] = $createdId;
                    }
                }

                if ($statement['ledger']) {
                    $balanceStatus = Account::updateLedgerBalance(
                        $accountId,
                        $statement['ledger']->balance,
                        $statement['ledger']->date,
                        count($statement['transactions'])
                    );
                    $accountResults[$accountId]['balance_status'] = $balanceStatus;
                    $accountResults[$accountId]['balance_date'] = $statement['ledger']->date;
                    if ($balanceStatus === 'protected') {
                        $warningCounts['balance'] = ($warningCounts['balance'] ?? 0) + 1;
                        $warningTotal++;
                        Log::write(
                            "Protected account $accountId from an empty zero OFX balance in $filename",
                            'WARNING'
                        );
                    } elseif ($balanceStatus === 'recovered') {
                        Log::write(
                            "Recovered account $accountId from a newer zero OFX balance using $filename",
                            'INFO'
                        );
                    }
                }
            }

            foreach ($accountResults as $accountId => &$accountResult) {
                if ($accountResult['inserted'] <= 0) {
                    continue;
                }
                Tag::applyToAccountTransactions((int)$accountId);
                CategoryTag::applyToAccountTransactions((int)$accountId);
                $classificationCounts = $this->classificationCounts($accountResult['inserted_ids']);
                $accountResult['tagged'] = $classificationCounts['tagged'];
                $accountResult['categorised'] = $classificationCounts['categorised'];
            }
            unset($accountResult);

            $this->db->commit();
        } catch (Throwable $e) {
            if ($transactionStarted && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            Log::write("OFX import rolled back for $filename: " . $e->getMessage(), 'ERROR');
            return $this->errorResult($filename, 'The file could not be imported safely. No transactions from it were saved.');
        }

        foreach ($accountResults as &$accountResult) {
            unset($accountResult['inserted_ids']);
        }
        unset($accountResult);
        $accounts = array_values($accountResults);
        $totals = $this->sumAccounts($accounts);
        $message = $totals['inserted'] > 0
            ? sprintf(
                'Imported %d new transaction%s.%s',
                $totals['inserted'],
                $totals['inserted'] === 1 ? '' : 's',
                $totals['balances_updated'] > 0
                    ? sprintf(' Refreshed %d account balance%s.', $totals['balances_updated'], $totals['balances_updated'] === 1 ? '' : 's')
                    : ''
            )
            : ($totals['balances_updated'] > 0
                ? sprintf(
                    'No new transactions; refreshed %d account balance%s%s',
                    $totals['balances_updated'],
                    $totals['balances_updated'] === 1 ? '' : 's',
                    $totals['duplicates'] > 0 ? sprintf(' and skipped %d duplicate%s.', $totals['duplicates'], $totals['duplicates'] === 1 ? '' : 's') : '.'
                )
            : ($totals['duplicates'] > 0
                ? sprintf('No new transactions; skipped %d duplicate%s.', $totals['duplicates'], $totals['duplicates'] === 1 ? '' : 's')
                : 'No transactions were found in this file.'));

        $result = [
            'file' => $filename,
            'status' => 'success',
            'message' => $message,
            'accounts' => $accounts,
            'totals' => [
                'accounts' => count($accounts),
                'inserted' => $totals['inserted'],
                'duplicates' => $totals['duplicates'],
                'tagged' => $totals['tagged'],
                'categorised' => $totals['categorised'],
                'balances_updated' => $totals['balances_updated'],
                'balances_protected' => $totals['balances_protected'],
                'warnings' => $warningTotal,
            ],
            'warning_counts' => $warningCounts,
        ];
        Log::write("OFX import completed for $filename: " . json_encode($result['totals']));
        return $result;
    }

    /**
     * Aggregate file results for the API response.
     *
     * @param array<int,array<string,mixed>> $files
     * @return array<string,mixed>
     */
    public static function summarise(array $files): array {
        $totals = [
            'files' => count($files),
            'successful_files' => 0,
            'failed_files' => 0,
            'accounts' => 0,
            'inserted' => 0,
            'duplicates' => 0,
            'tagged' => 0,
            'categorised' => 0,
            'balances_updated' => 0,
            'balances_protected' => 0,
            'warnings' => 0,
        ];

        foreach ($files as $file) {
            $success = ($file['status'] ?? 'error') === 'success';
            $totals[$success ? 'successful_files' : 'failed_files']++;
            foreach (['accounts', 'inserted', 'duplicates', 'tagged', 'categorised', 'balances_updated', 'balances_protected', 'warnings'] as $key) {
                $totals[$key] += (int)($file['totals'][$key] ?? 0);
            }
        }

        $status = $totals['failed_files'] === 0
            ? 'success'
            : ($totals['successful_files'] > 0 ? 'partial' : 'error');
        if ($status === 'success') {
            $message = $totals['inserted'] > 0
                ? sprintf('Import complete — %d new transaction%s added.', $totals['inserted'], $totals['inserted'] === 1 ? '' : 's')
                : 'Import complete — there were no new transactions to add.';
        } elseif ($status === 'partial') {
            $message = sprintf('%d file%s imported and %d failed.', $totals['successful_files'], $totals['successful_files'] === 1 ? '' : 's', $totals['failed_files']);
        } else {
            $message = 'No files could be imported.';
        }

        return [
            'status' => $status,
            'message' => $message,
            'totals' => $totals,
            'files' => array_values($files),
        ];
    }

    /** @return array<string,mixed> */
    public static function uploadErrorResult(string $filename, string $message): array {
        return [
            'file' => basename($filename) ?: 'statement.ofx',
            'status' => 'error',
            'message' => $message,
            'accounts' => [],
            'totals' => ['accounts' => 0, 'inserted' => 0, 'duplicates' => 0, 'tagged' => 0, 'categorised' => 0, 'balances_updated' => 0, 'balances_protected' => 0, 'warnings' => 0],
            'warning_counts' => [],
        ];
    }

    private function normaliseEncoding(string $contents): string {
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);
        $encoding = 'UTF-8';
        if (function_exists('mb_detect_encoding')) {
            $detected = mb_detect_encoding($contents, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);
            if ($detected) {
                $encoding = $detected;
            }
        }
        if ($encoding !== 'UTF-8') {
            if (function_exists('mb_convert_encoding')) {
                $converted = mb_convert_encoding($contents, 'UTF-8', $encoding);
            } elseif (function_exists('iconv')) {
                $converted = iconv($encoding, 'UTF-8//TRANSLIT', $contents);
            } else {
                $converted = $contents;
            }
            if ($converted !== false) {
                $contents = $converted;
            }
        }
        return preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/', '', $contents) ?? $contents;
    }

    private function findOrCreateAccount($account): int {
        $number = (string)$account->number;
        $sortCode = $account->sortCode === null ? null : (string)$account->sortCode;
        if ($sortCode === null) {
            $stmt = $this->db->prepare('SELECT `id` FROM `accounts` WHERE `account_number` = :number AND `sort_code` IS NULL LIMIT 1');
            $stmt->execute(['number' => $number]);
        } else {
            $stmt = $this->db->prepare('SELECT `id` FROM `accounts` WHERE `account_number` = :number AND `sort_code` = :sort_code LIMIT 1');
            $stmt->execute(['number' => $number, 'sort_code' => $sortCode]);
        }
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int)$id;
        }

        $matchedId = $this->findUniqueMaskedAccount($number, $sortCode);
        return $matchedId ?? Account::create((string)$account->name, $sortCode, $number);
    }

    private function findUniqueMaskedAccount(string $number, ?string $sortCode): ?int {
        if ($sortCode === null) {
            $stmt = $this->db->query('SELECT `id`, `account_number` FROM `accounts` WHERE `sort_code` IS NULL');
        } else {
            $stmt = $this->db->prepare('SELECT `id`, `account_number` FROM `accounts` WHERE `sort_code` = :sort_code');
            $stmt->execute(['sort_code' => $sortCode]);
        }

        $matches = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
            if ($this->maskedAccountNumbersMatch($number, (string)($candidate['account_number'] ?? ''))) {
                $matches[] = (int)$candidate['id'];
            }
        }
        return count($matches) === 1 ? $matches[0] : null;
    }

    private function maskedAccountNumbersMatch(string $left, string $right): bool {
        $left = strtoupper(trim($left));
        $right = strtoupper(trim($right));
        if ($left === '' || strlen($left) !== strlen($right) || (strpos($left, '*') === false && strpos($right, '*') === false)) {
            return false;
        }
        for ($index = 0, $length = strlen($left); $index < $length; $index++) {
            if ($left[$index] !== '*' && $right[$index] !== '*' && $left[$index] !== $right[$index]) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string,mixed> */
    private function prepareTransaction(int $accountId, $transaction): array {
        $substr = function_exists('mb_substr') ? 'mb_substr' : 'substr';
        $description = trim((string)$transaction->desc);
        $memo = trim((string)$transaction->memo);
        if ($description === '') {
            $description = $memo !== '' ? $memo : 'Unlabelled transaction';
        }
        if ($transaction->ref) {
            $memo .= ($memo === '' ? '' : ' ') . 'Ref:' . $substr((string)$transaction->ref, 0, Transaction::REF_MAX_LENGTH);
        }
        if ($transaction->check) {
            $memo .= ($memo === '' ? '' : ' ') . 'Chk:' . $substr((string)$transaction->check, 0, Transaction::CHECK_MAX_LENGTH);
        }

        $description = $substr($description, 0, Transaction::DESC_MAX_LENGTH);
        $memo = $memo === '' ? null : $substr($memo, 0, Transaction::MEMO_MAX_LENGTH);
        $bankId = trim((string)$transaction->bankId);
        $bankId = $bankId === '' ? null : $substr($bankId, 0, Transaction::ID_MAX_LENGTH);
        $type = $transaction->type === null ? null : $substr((string)$transaction->type, 0, Transaction::TYPE_MAX_LENGTH);
        $amount = (float)$transaction->amount;
        $amountString = number_format($amount, 2, '.', '');
        $normalise = static function (string $text): string {
            return preg_replace('/\s+/', ' ', strtoupper(trim($text))) ?? strtoupper(trim($text));
        };

        $identityParts = $bankId !== null
            ? ['fitid', $accountId, $bankId]
            : ['fallback', $accountId, (string)$transaction->date, $amountString, $normalise($description), $normalise($memo ?? ''), $type ?? ''];
        $ofxId = sha1(implode('|', $identityParts));
        $fingerprint = implode('|', [(string)$transaction->date, $amountString, $normalise($description), $normalise($memo ?? '')]);

        return [
            'date' => (string)$transaction->date,
            'amount' => $amount,
            'description' => $description,
            'memo' => $memo,
            'type' => $type,
            'bank_id' => $bankId,
            'ofx_id' => $ofxId,
            'identity_key' => ($bankId !== null ? 'fitid:' . $accountId . ':' . $bankId : 'fallback:' . $ofxId),
            'fingerprint' => $fingerprint,
        ];
    }

    /** @param array<int,array<string,mixed>> $accounts */
    private function sumAccounts(array $accounts): array {
        $totals = ['inserted' => 0, 'duplicates' => 0, 'tagged' => 0, 'categorised' => 0, 'balances_updated' => 0, 'balances_protected' => 0];
        foreach ($accounts as $account) {
            foreach (['inserted', 'duplicates', 'tagged', 'categorised'] as $key) {
                $totals[$key] += (int)($account[$key] ?? 0);
            }
            if (in_array($account['balance_status'] ?? '', ['updated', 'recovered'], true)) {
                $totals['balances_updated']++;
            } elseif (($account['balance_status'] ?? '') === 'protected') {
                $totals['balances_protected']++;
            }
        }
        return $totals;
    }

    /** @param array<int,int> $transactionIds */
    private function classificationCounts(array $transactionIds): array {
        if (!$transactionIds) {
            return ['tagged' => 0, 'categorised' => 0];
        }
        $placeholders = implode(',', array_fill(0, count($transactionIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT SUM(CASE WHEN tag_id IS NOT NULL THEN 1 ELSE 0 END) AS tagged, "
            . "SUM(CASE WHEN category_id IS NOT NULL THEN 1 ELSE 0 END) AS categorised "
            . "FROM transactions WHERE id IN ($placeholders)"
        );
        $stmt->execute(array_map('intval', $transactionIds));
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'tagged' => (int)($row['tagged'] ?? 0),
            'categorised' => (int)($row['categorised'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    private function errorResult(string $filename, string $message): array {
        return self::uploadErrorResult($filename, $message);
    }
}
