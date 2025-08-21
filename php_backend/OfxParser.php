<?php
// Parses OFX data using SimpleXML and validates required elements.
require_once __DIR__ . '/Account.php';
require_once __DIR__ . '/Ledger.php';
require_once __DIR__ . '/Transaction.php';

use Ofx\Account as OfxAccount;
use Ofx\Ledger as OfxLedger;
use Ofx\Transaction as OfxTransaction;

class OfxParser {
    public static function parse(string $data, bool $strict = false): array {
        $warnings = [];
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
        $opts = LIBXML_NOERROR | LIBXML_NOWARNING;
        if (defined('LIBXML_BIGLINES')) {
            $opts |= LIBXML_BIGLINES;
        }
        $xml = simplexml_load_string($data, 'SimpleXMLElement', $opts);
        if (!$xml) {
            throw new Exception('Failed to parse OFX');
        }

        $statement = self::getStatement($xml);

        // BANKTRANLIST boundaries for date validation
        $bankTranList = $statement->xpath('.//BANKTRANLIST');
        $dtStart = null;
        $dtEnd = null;
        if ($bankTranList) {
            $dtStart = self::parseDate((string)$bankTranList[0]->DTSTART, $warnings, self::line($bankTranList[0]->DTSTART ?? null), $strict);
            $dtEnd = self::parseDate((string)$bankTranList[0]->DTEND, $warnings, self::line($bankTranList[0]->DTEND ?? null), $strict);
        }

        $account = self::parseAccount($statement, $warnings, $strict);
        $ledger = self::parseLedger($statement, $warnings, $strict);
        $transactions = self::parseTransactions($statement, $dtStart, $dtEnd, $warnings, $strict);

        return [
            'account' => $account,
            'ledger' => $ledger,
            'transactions' => $transactions,
            'warnings' => $warnings,
        ];
    }

    private static function getStatement(SimpleXMLElement $xml): SimpleXMLElement {
        $stmts = $xml->xpath('(//BANKMSGSRSV1/STMTTRNRS/STMTRS | //CREDITCARDMSGSRSV1/CCSTMTTRNRS/CCSTMTRS)[1]');
        if (!$stmts) {
            throw new Exception('Missing statement');
        }
        return $stmts[0];
    }

    private static function parseAccount(SimpleXMLElement $stmt, array &$warnings, bool $strict): OfxAccount {
        $acctNode = $stmt->xpath('.//BANKACCTFROM | .//CCACCTFROM | .//ACCTFROM');
        $rawAcctId = $acctNode ? trim((string)$acctNode[0]->ACCTID) : '';
        // Some providers mask account numbers (e.g. 552213******8609). Remove any
        // characters except alphanumerics and asterisks so masked IDs are stored
        // consistently without losing placeholder digits.
        $accountNumber = preg_replace('/[^A-Za-z0-9*]/', '', $rawAcctId);

        if ($accountNumber === '') {
            if ($strict) {
                throw new Exception('Missing account number');
            }
            self::log($warnings, 'Missing account number, using placeholder', self::line($acctNode[0] ?? $stmt));
            $accountNumber = '00000000';
        }

        // Credit card statements may include a BANKID that is not a real sort code.
        // Identify CCACCTFROM nodes explicitly and ignore any BANKID so the account
        // is treated as a credit card when imported.
        $sortCode = $acctNode ? trim((string)$acctNode[0]->BANKID) ?: null : null;
        if ($acctNode && strtoupper($acctNode[0]->getName()) === 'CCACCTFROM') {
            $sortCode = null;
        }

        $accountName = $acctNode ? trim((string)$acctNode[0]->ACCTNAME) ?: 'Default' : 'Default';

        return new OfxAccount($sortCode, $accountNumber, $accountName);
    }

    private static function parseLedger(SimpleXMLElement $stmt, array &$warnings, bool $strict): ?OfxLedger {
        $ledgerNode = $stmt->xpath('.//LEDGERBAL');
        if ($ledgerNode) {
            $balAmt = self::normaliseAmount((string)$ledgerNode[0]->BALAMT);
            $dtAsOf = self::parseDate((string)$ledgerNode[0]->DTASOF, $warnings, self::line($ledgerNode[0]->DTASOF ?? null), $strict);
            if ($balAmt !== null && $dtAsOf !== null) {
                return new OfxLedger($balAmt, $dtAsOf);
            }
        }
        return null;
    }

    private static function parseTransactions(SimpleXMLElement $stmt, ?string $dtStart, ?string $dtEnd, array &$warnings, bool $strict): array {
        $stmtTrns = $stmt->xpath('.//STMTTRN');
        if (!$stmtTrns) {
            throw new Exception('Missing STMTTRN');
        }
        $transactions = [];
        $running = null;
        foreach ($stmtTrns as $trn) {
            $line = self::line($trn);
            $raw = trim($trn->asXML());
            $dt = self::parseDate((string)$trn->DTPOSTED, $warnings, self::line($trn->DTPOSTED ?? null), $strict);
            $amt = self::normaliseAmount((string)$trn->TRNAMT);
            if ($dt === null || $amt === null) {
                $msg = 'Missing DTPOSTED or TRNAMT';
                if ($strict) {
                    throw new Exception($msg);
                }
                self::log($warnings, $msg, $line, $raw);
                continue;
            }
            if (($dtStart && $dt < $dtStart) || ($dtEnd && $dt > $dtEnd)) {
                $msg = 'DTPOSTED outside BANKTRANLIST window';
                if ($strict) {
                    throw new Exception($msg);
                }
                self::log($warnings, $msg, $line, $raw);
            }

            $trnType = $trn->TRNTYPE ? strtoupper(trim((string)$trn->TRNTYPE)) : null;
            $memo = trim((string)$trn->MEMO);
            if ($trnType === null) {
                if ($strict) {
                    throw new Exception('Missing TRNTYPE');
                }
                self::log($warnings, 'Missing TRNTYPE, using UNKNOWN', $line, $raw);
                $trnType = 'UNKNOWN';
            }
            if ($memo === '') {
                if ($strict) {
                    throw new Exception('Missing MEMO');
                }
                self::log($warnings, 'Missing MEMO, using placeholder', $line, $raw);
                $memo = 'N/A';
            }

            // Check running balance if provided
            if ($trn->RUNNINGBAL && $trn->RUNNINGBAL->BALAMT) {
                $bal = self::normaliseAmount((string)$trn->RUNNINGBAL->BALAMT);
                if ($bal !== null) {
                    if ($running !== null) {
                        $expected = $running + $amt;
                        if (abs($expected - $bal) > 0.01) {
                            $msg = 'Running balance mismatch';
                            if ($strict) {
                                throw new Exception($msg);
                            }
                            self::log($warnings, $msg, $line, $raw);
                        }
                    }
                    $running = $bal;
                }
            } elseif ($running !== null) {
                $running += $amt;
            } else {
                $running = $amt;
            }

            // Capture unknown tags
            $extensions = [];
            foreach ($trn->children() as $child) {
                $name = strtoupper($child->getName());
                if (!in_array($name, ['DTPOSTED','TRNAMT','NAME','MEMO','TRNTYPE','REFNUM','CHECKNUM','FITID','RUNNINGBAL'])) {
                    $extensions[$name] = trim((string)$child);
                }
            }

            $transactions[] = new OfxTransaction(
                $dt,
                $amt,
                trim((string)$trn->NAME),
                $memo,
                $trnType,
                trim((string)$trn->REFNUM),
                trim((string)$trn->CHECKNUM),
                trim((string)$trn->FITID),
                $raw,
                $extensions
            );
        }
        return $transactions;
    }

    private static function normaliseAmount(string $value): ?float {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        // Remove commas, spaces and stray symbols
        $value = preg_replace('/[^0-9\-\.]/', '', $value);
        if ($value === '' || !is_numeric($value)) {
            return null;
        }
        return (float)$value;
    }

    private static function parseDate(string $value, array &$warnings, ?int $line = null, bool $strict = false): ?string {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        // Capture YYYYMMDD and optional time + timezone
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})(\d{0,6})(?:\[([^\]]+)\]|([+-]\d{4}))?/', $value, $m)) {
            if ($strict) {
                throw new Exception('Invalid date format');
            }
            self::log($warnings, 'Invalid date format', $line, $value);
            return null;
        }
        $time = str_pad($m[4] ?? '', 6, '0');
        $dt = DateTime::createFromFormat('YmdHis', $m[1] . $m[2] . $m[3] . $time, new DateTimeZone('UTC'));
        if (!$dt) {
            if ($strict) {
                throw new Exception('Failed to parse date');
            }
            self::log($warnings, 'Failed to parse date', $line, $value);
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
        $year = (int)$dt->format('Y');
        if ($year < 1900) {
            self::log($warnings, 'Date before 1900 clamped', $line, $value);
            $dt = new DateTime('1900-01-01', new DateTimeZone('UTC'));
        } elseif ($year > 2100) {
            self::log($warnings, 'Date after 2100 clamped', $line, $value);
            $dt = new DateTime('2100-12-31', new DateTimeZone('UTC'));
        }
        return $dt->format('Y-m-d');
    }

    private static function line($node): ?int {
        if (!$node) {
            return null;
        }
        try {
            return dom_import_simplexml($node)->getLineNo();
        } catch (Exception $e) {
            return null;
        }
    }

    private static function log(array &$warnings, string $msg, ?int $line, string $context = ''): void {
        $prefix = $line ? 'Line ' . $line . ': ' : '';
        if ($context !== '') {
            $msg .= ' (' . substr($context, 0, 120) . ')';
        }
        $warnings[] = $prefix . $msg;
    }
}
?>
