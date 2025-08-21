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
        // Normalise line endings and attempt to decode using a tolerant charset
        $data = str_replace(["\r\n", "\r"], "\n", $data);
        $data = @iconv('UTF-8', 'UTF-8//IGNORE', $data);

        // Remove any OFX headers and locate the root tag case-insensitively
        if (($pos = stripos($data, '<OFX')) === false) {
            throw new Exception('Missing <OFX> root element');
        }
        $data = substr($data, $pos);

        // Convert tags to upper-case so SimpleXML can be used case-insensitively
        $data = preg_replace_callback('/<\/?([a-z0-9]+)([^>]*)>/i', function ($m) {
            return '<' . ($m[0][1] === '/' ? '/' : '') . strtoupper($m[1]) . $m[2] . '>';
        }, $data);

        // Convert SGML-style tags (<TAG>value) to XML by inserting a closing tag
        // whenever a tag's value is followed by another tag or the end of the
        // file. This also covers cases where tags appear consecutively without
        // newlines, a format some banks use for compact OFX exports.
        // Tags that already include an explicit closing tag (</TAG>) are left
        // untouched to avoid double-closing.
        $data = preg_replace(
            '/<([^>\s]+)>([^<\n]+)(?!(?:\n)?<\/\1>)(?=(?:\n?<|$))/',
            '<$1>$2</$1>',
            $data
        );

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($data, 'SimpleXMLElement', LIBXML_NOERROR | LIBXML_NOWARNING);
        if (!$xml) {
            throw new Exception('Failed to parse OFX');
        }

        $statement = self::getStatement($xml);
        $account = self::parseAccount($statement);
        $ledger = self::parseLedger($statement);
        $transactions = self::parseTransactions($statement);

        return [
            'account' => $account,
            'ledger' => $ledger,
            'transactions' => $transactions,
        ];
    }

    private static function getStatement(SimpleXMLElement $xml): SimpleXMLElement {
        $stmts = $xml->xpath('(//BANKMSGSRSV1/STMTTRNRS/STMTRS | //CREDITCARDMSGSRSV1/CCSTMTTRNRS/CCSTMTRS)[1]');
        if (!$stmts) {
            throw new Exception('Missing statement');
        }
        return $stmts[0];
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

    private static function parseTransactions(SimpleXMLElement $stmt): array {
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
            $transactions[] = new OfxTransaction(
                $dt,
                $amt,
                trim((string)$trn->NAME),
                trim((string)$trn->MEMO),
                $trn->TRNTYPE ? strtoupper(trim((string)$trn->TRNTYPE)) : null,
                trim((string)$trn->REFNUM),
                trim((string)$trn->CHECKNUM),
                trim((string)$trn->FITID)
            );
        }
        return $transactions;
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
