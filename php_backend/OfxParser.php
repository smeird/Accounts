<?php
// Parses OFX data using SimpleXML and validates required elements.
require_once __DIR__ . '/Account.php';
require_once __DIR__ . '/Ledger.php';
require_once __DIR__ . '/Transaction.php';

use Ofx\Account as OfxAccount;
use Ofx\Ledger as OfxLedger;
use Ofx\Transaction as OfxTransaction;

class OfxParser {
    private const MAX_AMOUNT = 999999999999.99;
    public static function parse(string $data): array {
        $data = self::prepare($data);

        libxml_use_internal_errors(true);
        $reader = new XMLReader();
        if (!$reader->XML($data, null, LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new Exception('Failed to initialise XML reader');
        }

        $statements = [];
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && ($reader->name === 'STMTTRNRS' || $reader->name === 'CCSTMTTRNRS')) {
                $outer = $reader->readOuterXML();
                if ($outer === '') {
                    continue;
                }
                $stmtXml = simplexml_load_string($outer, 'SimpleXMLElement', LIBXML_NOERROR | LIBXML_NOWARNING);
                if (!$stmtXml) {
                    continue;
                }
                $stmts = $stmtXml->xpath('STMTRS | CCSTMTRS');
                if (!$stmts) {
                    continue;
                }
                $stmt = $stmts[0];
                $statements[] = [
                    'account' => self::parseAccount($stmt),
                    'ledger' => self::parseLedger($stmt),
                    'transactions' => self::parseTransactions($stmt),
                ];
            }
        }

        if (!$statements) {
            throw new Exception('Missing statement');
        }

        return $statements;
    }

    private static function prepare(string $data): string {
        // Normalise line endings and attempt to decode using a tolerant charset
        $data = str_replace(["\r\n", "\r"], "\n", $data);
        $data = @iconv('UTF-8', 'UTF-8//IGNORE', $data);

        // Detect OFX 1.x SGML headers or 2.x XML headers and strip them
        if (preg_match('/^\s*OFXHEADER:/i', $data)) {
            if (($pos = stripos($data, '<OFX')) !== false) {
                $data = substr($data, $pos);
            }
        } else {
            if (($pos = stripos($data, '<OFX')) !== false) {
                $data = substr($data, $pos);
            }
        }

        if (stripos($data, '<OFX') === false) {
            throw new Exception('Missing <OFX> root element');
        }

        // Convert tag names to upper-case for case-insensitive parsing
        $data = preg_replace_callback('/<\/?([a-z0-9]+)([^>]*)>/i', function ($m) {
            return '<' . ($m[0][1] === '/' ? '/' : '') . strtoupper($m[1]) . $m[2] . '>';
        }, $data);

        // Repair unbalanced SGML-style tags using a simple stack heuristic
        $data = self::closeTags($data);


        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($data, 'SimpleXMLElement', LIBXML_NOERROR | LIBXML_NOWARNING);
        if (!$xml) {
            throw new Exception('Failed to parse OFX');
        }

        $profile = self::loadProfile($xml);

        $statement = self::getStatement($xml);
        $account = self::parseAccount($statement);
        $ledger = self::parseLedger($statement);
        $transactions = self::parseTransactions($statement, $profile);

        return [
            'account' => $account,
            'ledger' => $ledger,
            'transactions' => $transactions,
        ];

    }

    private static function closeTags(string $data): string {
        $parts = [];
        preg_match_all('/<(\/)?([A-Za-z0-9]+)[^>]*>|[^<]+/', $data, $parts, PREG_SET_ORDER);
        $stack = [];
        $out = '';
        $prevText = false;
        foreach ($parts as $p) {
            $token = $p[0];
            if ($token[0] === '<') {
                $isEnd = $p[1] === '/';
                $name = strtoupper($p[2]);
                if (!$isEnd) {
                    if ($prevText && !empty($stack)) {
                        $out .= '</' . array_pop($stack) . '>';
                    }
                    $out .= '<' . $name . '>';
                    $stack[] = $name;
                    $prevText = false;
                } else {
                    while (!empty($stack) && end($stack) !== $name) {
                        $out .= '</' . array_pop($stack) . '>';
                    }
                    if (!empty($stack) && end($stack) === $name) {
                        array_pop($stack);
                        $out .= '</' . $name . '>';
                    }
                    $prevText = false;
                }
            } else {
                if (!empty($stack)) {
                    $out .= htmlspecialchars($token, ENT_NOQUOTES | ENT_XML1, 'UTF-8');
                    $prevText = trim($token) !== '';
                }
            }
        }

        if ($prevText && !empty($stack)) {
            $out .= '</' . array_pop($stack) . '>';
        }
        while (!empty($stack)) {
            $out .= '</' . array_pop($stack) . '>';
        }
        return $out;
    }

    private static function parseAccount(SimpleXMLElement $stmt): OfxAccount {
        $acctNode = $stmt->xpath('.//BANKACCTFROM | .//CCACCTFROM | .//ACCTFROM');
        $rawAcctId = $acctNode ? trim((string)$acctNode[0]->ACCTID) : '';
        // Some providers mask account numbers (e.g. 552213******8609). Remove any
        // characters except alphanumerics and asterisks so masked IDs are stored
        // consistently without losing placeholder digits.
        $accountNumber = preg_replace('/[^A-Za-z0-9*]/', '', $rawAcctId);

        if ($accountNumber === '') {
            throw new Exception('Missing account number');
        }

        // Credit card statements may include a BANKID that is not a real sort code.
        // Identify CCACCTFROM nodes explicitly and ignore any BANKID so the account
        // is treated as a credit card when imported.
        $sortCode = trim((string)$acctNode[0]->BANKID) ?: null;
        if (strtoupper($acctNode[0]->getName()) === 'CCACCTFROM') {
            $sortCode = null;
        }

        $accountName = trim((string)$acctNode[0]->ACCTNAME) ?: 'Default';
        $currency = self::normaliseCurrency((string)$stmt->CURDEF);

        return new OfxAccount($sortCode, $accountNumber, $accountName, $currency);
    }

    private static function parseLedger(SimpleXMLElement $stmt): ?OfxLedger {
        $ledgerNode = $stmt->xpath('.//LEDGERBAL');
        if ($ledgerNode) {
            $balAmt = self::normaliseAmount((string)$ledgerNode[0]->BALAMT);
            $dtAsOf = self::parseDate((string)$ledgerNode[0]->DTASOF);
            $currency = self::normaliseCurrency((string)$ledgerNode[0]->CURDEF ?: (string)$stmt->CURDEF);
            if ($balAmt !== null && $dtAsOf !== null) {
                return new OfxLedger($balAmt, $dtAsOf, $currency);
            }
        }
        return null;
    }

    private static function parseTransactions(SimpleXMLElement $stmt, array $profile): array {
        $stmtTrns = $stmt->xpath('.//STMTTRN');
        if (!$stmtTrns) {
            throw new Exception('Missing STMTTRN');
        }
        $transactions = [];
        foreach ($stmtTrns as $trn) {
            $dt = self::parseDate((string)$trn->DTPOSTED);
            $amt = self::normaliseAmount((string)$trn->TRNAMT);
            if ($dt === null || $amt === null) {
                throw new Exception('Missing DTPOSTED or TRNAMT');
            }
            $memo = self::applyFieldProfile('MEMO', (string)$trn->MEMO, $profile);
            $ref = self::applyFieldProfile('REFNUM', (string)$trn->REFNUM, $profile);
            $check = self::applyFieldProfile('CHECKNUM', (string)$trn->CHECKNUM, $profile);
            $transactions[] = new OfxTransaction(
                $dt,
                $amt,
                trim((string)$trn->NAME),
                $memo,
                $trn->TRNTYPE ? strtoupper(trim((string)$trn->TRNTYPE)) : null,
                $ref,
                $check,
                trim((string)$trn->FITID)
            );
        }
        return $transactions;
    }

    private static function loadProfile(SimpleXMLElement $xml): array {
        $fi = $xml->xpath('(//SIGNONMSGSRSV1/SONRS/FI)[1]');
        $id = '';
        if ($fi) {
            $id = strtolower(trim((string)($fi[0]->FID ?: $fi[0]->ORG)));
        }
        $dir = __DIR__ . '/profiles';
        $file = $dir . '/' . ($id !== '' ? $id : 'default') . '.json';
        if (!is_file($file)) {
            $file = $dir . '/default.json';
        }
        $cfg = [];
        if (is_file($file)) {
            $json = file_get_contents($file);
            $cfg = json_decode($json, true) ?: [];
        }
        return $cfg;
    }

    private static function applyFieldProfile(string $field, string $value, array $profile): string {
        $cfg = $profile['fields'][$field] ?? [];
        $value = preg_replace('/\s+/', ' ', trim($value));
        if ($value === '') {
            return $value;
        }
        if (!empty($cfg['regex'])) {
            $value = preg_replace($cfg['regex'], '', $value);
        }
        if (!empty($cfg['uppercase'])) {
            $value = strtoupper($value);
        }
        if (!empty($cfg['max'])) {
            $value = substr($value, 0, (int)$cfg['max']);
        }
        return $value;
    }

    private static function normaliseAmount(string $value): ?float {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $value = str_replace([',', ' '], '', $value);
        $neg = false;
        if (preg_match('/^\((.*)\)$/', $value, $m)) {
            $neg = true;
            $value = $m[1];
        } elseif (substr($value, -1) === '-') {
            $neg = true;
            $value = substr($value, 0, -1);
        }
        $value = preg_replace('/[^0-9\.-]/', '', $value);
        if ($value === '' || !is_numeric($value)) {
            return null;
        }
        $num = (float)$value;
        if ($neg) {
            $num = -abs($num);
        }
        if ($num > self::MAX_AMOUNT) {
            $num = self::MAX_AMOUNT;
        } elseif ($num < -self::MAX_AMOUNT) {
            $num = -self::MAX_AMOUNT;
        }
        return $num;
    }

    private static function normaliseCurrency(?string $code): string {
        $code = strtoupper(preg_replace('/[^A-Z]/', '', $code ?? ''));
        if ($code === '') {
            return 'GBP';
        }
        $map = [
            'UKL' => 'GBP',
            'GBR' => 'GBP',
        ];
        if (isset($map[$code])) {
            return $map[$code];
        }
        if (preg_match('/^[A-Z]{3}$/', $code)) {
            return $code;
        }
        return 'GBP';
    }

    private static function parseDate(string $value): ?string {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        // Capture YYYYMMDD and optional time + timezone
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})(\d{0,6})(?:\[([^\]]+)\]|([+-]\d{4}))?/', $value, $m)) {
            return null;
        }
        $time = str_pad($m[4] ?? '', 6, '0');
        $dt = DateTime::createFromFormat('YmdHis', $m[1] . $m[2] . $m[3] . $time, new DateTimeZone('UTC'));
        if (!$dt) {
            return null;
        }
        // Apply timezone offset if present (e.g. +0100 or -0500)
        if (!empty($m[6])) {
            $offset = $m[6];
            $sign = $offset[0] === '-' ? -1 : 1;
            $hours = (int)substr($offset, 1, 2);
            $mins = (int)substr($offset, 3, 2);
            $dt->modify((-1 * $sign * $hours) . ' hours');
            if ($mins) {
                $dt->modify((-1 * $sign * $mins) . ' minutes');
            }
        }
        return $dt->format('Y-m-d');
    }
}
?>
