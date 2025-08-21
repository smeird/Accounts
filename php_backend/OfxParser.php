<?php
// Parses OFX data using SimpleXML and validates required elements.
require_once __DIR__ . '/Account.php';
require_once __DIR__ . '/Ledger.php';
require_once __DIR__ . '/Transaction.php';

use Ofx\Account as OfxAccount;
use Ofx\Ledger as OfxLedger;
use Ofx\Transaction as OfxTransaction;

class OfxParser {

    private const MAX_AMOUNT = 1000000000; // clamp extremely large values

    public static function parse(string $data, bool $strict = false): array {
        $warnings = [];

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
        $opts = LIBXML_NOERROR | LIBXML_NOWARNING;
        if (defined('LIBXML_BIGLINES')) {
            $opts |= LIBXML_BIGLINES;
        }
        $xml = simplexml_load_string($data, 'SimpleXMLElement', $opts);
        if (!$xml) {
            throw new Exception('Failed to parse OFX');
        }

        $profile = self::loadProfile($xml);

        $statements = self::getStatements($xml);
        $parsed = [];
        foreach ($statements as $statement) {
            $warnings = [];
            $currency = self::normaliseCurrency((string)($statement->CURDEF ?? ''));

            // BANKTRANLIST boundaries for date validation
            $bankTranList = $statement->xpath('.//BANKTRANLIST');
            $dtStart = null;
            $dtEnd = null;
            if ($bankTranList) {
                $dtStart = self::parseDate((string)$bankTranList[0]->DTSTART, $warnings, self::line($bankTranList[0]->DTSTART ?? null), $strict);
                $dtEnd = self::parseDate((string)$bankTranList[0]->DTEND, $warnings, self::line($bankTranList[0]->DTEND ?? null), $strict);
            }

            $account = self::parseAccount($statement, $warnings, $strict, $currency);
            $ledger = self::parseLedger($statement, $warnings, $strict, $currency);
            $transactions = self::parseTransactions($statement, $dtStart, $dtEnd, $warnings, $strict);

            $parsed[] = [
                'account' => $account,
                'ledger' => $ledger,
                'transactions' => $transactions,
                'warnings' => $warnings,
            ];
        }

        return $parsed;

    }

    private static function getStatements(SimpleXMLElement $xml): array {
        $stmts = [];
        $bank = $xml->xpath('//STMTRS');
        $card = $xml->xpath('//CCSTMTRS');
        if ($bank) {
            $stmts = array_merge($stmts, $bank);
        }
        if ($card) {
            $stmts = array_merge($stmts, $card);
        }
        if (empty($stmts)) {
            throw new Exception('Missing STMTRS');
        }
        return $stmts;
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

    private static function parseAccount(SimpleXMLElement $stmt, array &$warnings, bool $strict, string $currency = 'GBP'): OfxAccount {
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


        return new OfxAccount($sortCode, $accountNumber, $accountName, $currency);
    }

    private static function parseLedger(SimpleXMLElement $stmt, array &$warnings, bool $strict, string $currency = 'GBP'): ?OfxLedger {
        $ledgerNode = $stmt->xpath('.//LEDGERBAL');
        if ($ledgerNode) {
            $balAmt = self::normaliseAmount((string)$ledgerNode[0]->BALAMT);

            $dtAsOf = self::parseDate((string)$ledgerNode[0]->DTASOF, $warnings, self::line($ledgerNode[0]->DTASOF ?? null), $strict);

            if ($balAmt !== null && $dtAsOf !== null) {
                return new OfxLedger($balAmt, $dtAsOf, $currency);
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
