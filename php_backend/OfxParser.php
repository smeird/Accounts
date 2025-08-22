<?php
// Parses OFX data using SimpleXML and validates required elements.
require_once __DIR__ . '/Account.php';
require_once __DIR__ . '/Ledger.php';
require_once __DIR__ . '/Transaction.php';
require_once __DIR__ . '/TransactionType.php';

use Ofx\Account as OfxAccount;
use Ofx\Ledger as OfxLedger;
use Ofx\Transaction as OfxTransaction;
use Ofx\TransactionType;

class OfxParser {

    private const TRNTYPE_MAP = [
        'CREDIT' => TransactionType::CREDIT,
        'DEBIT' => TransactionType::DEBIT,
        'INT' => TransactionType::INT,
        'DIV' => TransactionType::DIV,
        'FEE' => TransactionType::FEE,
        'SRVCHG' => TransactionType::SRVCHG,
        'DEP' => TransactionType::DEP,
        'ATM' => TransactionType::ATM,
        'POS' => TransactionType::POS,
        'XFER' => TransactionType::XFER,
        'CHECK' => TransactionType::CHECK,
        'PAYMENT' => TransactionType::PAYMENT,
        'CASH' => TransactionType::CASH,
        'DIRECTDEP' => TransactionType::DIRECTDEP,
        'DIRECTDEBIT' => TransactionType::DIRECTDEBIT,
        'REPEATPMT' => TransactionType::REPEATPMT,
        'HOLD' => TransactionType::HOLD,
        'OTHER' => TransactionType::OTHER,
    ];

    const MAX_AMOUNT = 1000000000; // clamp extremely large values

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

        $reader = new XMLReader();
        if (!$reader->XML($data, null, $opts)) {
            throw new Exception('Failed to parse OFX');
        }

        $parsed = [];
        $hasStatement = false;
        $offset = 0;
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT &&
                ($reader->name === 'STMTRS' || $reader->name === 'CCSTMTRS')) {
                $hasStatement = true;
                $parsed[] = self::parseStatement($reader, $strict, $data, $offset);
            }
        }

        if (!$hasStatement) {
            throw new Exception('Missing STMTRS');
        }

        $reader->close();
        return $parsed;

    }

    private static function parseStatement(XMLReader $reader, bool $strict, string $data, int &$offset): array {
        $warnings = [];
        $currency = 'GBP';
        $account = null;
        $ledger = null;
        $transactions = [];
        $dtStart = null;
        $dtEnd = null;
        $running = null;

        $depth = $reader->depth;
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                switch ($reader->name) {
                    case 'CURDEF':
                        $currency = self::normaliseCurrency(trim($reader->readString()));
                        break;
                    case 'BANKACCTFROM':
                    case 'CCACCTFROM':
                    case 'ACCTFROM':
                        $xml = $reader->readOuterXML();
                        $node = @simplexml_load_string($xml);
                        if ($node) {
                            $account = self::parseAccountNode($node, $warnings, $strict, $currency);
                        }
                        break;
                    case 'LEDGERBAL':
                        $xml = $reader->readOuterXML();
                        $node = @simplexml_load_string($xml);
                        if ($node) {
                            $ledger = self::parseLedgerNode($node, $warnings, $strict, $currency);
                        }
                        break;
                    case 'BANKTRANLIST':
                        $btDepth = $reader->depth;
                        while ($reader->read()) {
                            if ($reader->nodeType === XMLReader::ELEMENT) {
                                if ($reader->name === 'DTSTART') {
                                    $dtStart = self::parseDate(trim($reader->readString()), $warnings, null, $strict);
                                } elseif ($reader->name === 'DTEND') {
                                    $dtEnd = self::parseDate(trim($reader->readString()), $warnings, null, $strict);
                                  } elseif ($reader->name === 'STMTTRN') {
                                      $line = null;
                                      $byte = null;
                                      if (preg_match('/<STMTTRN[^>]*>/i', $data, $m, PREG_OFFSET_CAPTURE, $offset)) {
                                          $byte = $m[0][1];
                                          $line = substr_count($data, "\n", 0, $byte) + 1;
                                          $offset = $byte + strlen($m[0][0]);
                                      }
                                      $xml = $reader->readOuterXML();
                                      $trn = @simplexml_load_string($xml);
                                      if ($trn) {
                                          $tx = self::parseTransaction($trn, $dtStart, $dtEnd, $warnings, $strict, $running, $line, $byte);
                                          if ($tx) {
                                              $transactions[] = $tx;
                                          }
                                      }
                                  }
                            } elseif ($reader->nodeType === XMLReader::END_ELEMENT &&
                                $reader->depth === $btDepth && $reader->name === 'BANKTRANLIST') {
                                break;
                            }
                        }
                        break;
                }
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT &&
                $reader->depth === $depth && ($reader->name === 'STMTRS' || $reader->name === 'CCSTMTRS')) {
                break;
            }
        }

        if (!$account) {
            $msg = 'Missing account number, using placeholder';
            if ($strict) {
                throw new Exception($msg);
            }
            self::log($warnings, $msg, null);
            $account = new OfxAccount(null, '00000000', 'Default', $currency);
        }

        return [
            'account' => $account,
            'ledger' => $ledger,
            'transactions' => $transactions,
            'warnings' => $warnings,
        ];
    }

    private static function parseAccountNode(SimpleXMLElement $acctNode, array &$warnings, bool $strict, string $currency): OfxAccount {
        $rawAcctId = trim((string)$acctNode->ACCTID);
        $accountNumber = preg_replace('/[^A-Za-z0-9*]/', '', $rawAcctId);
        if ($accountNumber === '') {
            if ($strict) {
                throw new Exception('Missing account number');
            }
            self::log($warnings, 'Missing account number, using placeholder', null);
            $accountNumber = '00000000';
        }

        $sortCode = trim((string)$acctNode->BANKID) ?: null;
        if (strtoupper($acctNode->getName()) === 'CCACCTFROM') {
            $sortCode = null;
        }

        $accountName = trim((string)$acctNode->ACCTNAME) ?: 'Default';

        return new OfxAccount($sortCode, $accountNumber, $accountName, $currency);
    }

    private static function parseLedgerNode(SimpleXMLElement $ledgerNode, array &$warnings, bool $strict, string $currency): ?OfxLedger {
        $balAmt = self::normaliseAmount((string)$ledgerNode->BALAMT);
        $dtAsOf = self::parseDate((string)$ledgerNode->DTASOF, $warnings, null, $strict);
        if ($balAmt !== null && $dtAsOf !== null) {
            return new OfxLedger($balAmt, $dtAsOf, $currency);
        }
        return null;
    }

    private static function parseTransaction(SimpleXMLElement $trn, ?string $dtStart, ?string $dtEnd, array &$warnings, bool $strict, ?float &$running, ?int $line = null, ?int $byte = null): ?OfxTransaction {
        $raw = trim($trn->asXML());
        $dt = self::parseDate((string)$trn->DTPOSTED, $warnings, self::line($trn->DTPOSTED) ?? $line, $strict);
        $amt = self::normaliseAmount((string)$trn->TRNAMT);
        if ($dt === null || $amt === null) {
            $msg = 'Missing DTPOSTED or TRNAMT';
            if ($strict) {
                throw new Exception($msg);
            }
            self::log($warnings, $msg, $line, $raw);
            return null;
        }
        if (($dtStart && $dt < $dtStart) || ($dtEnd && $dt > $dtEnd)) {
            $msg = 'DTPOSTED outside BANKTRANLIST window';
                if ($strict) {
                    throw new Exception($msg);
                }
                self::log($warnings, $msg, $line, $raw);
            }

        $trnTypeRaw = $trn->TRNTYPE ? strtoupper(trim((string)$trn->TRNTYPE)) : null;
        $memo = trim((string)$trn->MEMO);
        if ($trnTypeRaw === null) {
            if ($strict) {
                throw new Exception('Missing TRNTYPE');
            }
            self::log($warnings, 'Missing TRNTYPE, using UNKNOWN', $line, $raw);
            $trnType = TransactionType::UNKNOWN;
        } else {
            $trnType = self::TRNTYPE_MAP[$trnTypeRaw] ?? TransactionType::UNKNOWN;
        }
        if ($memo === '') {
            if ($strict) {
                throw new Exception('Missing MEMO');
            }
            self::log($warnings, 'Missing MEMO, using placeholder', $line, $raw);
            $memo = 'N/A';
        }

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

        $extensions = [];
        foreach ($trn->children() as $child) {
            $name = strtoupper($child->getName());
            if (!in_array($name, ['DTPOSTED','TRNAMT','NAME','MEMO','TRNTYPE','REFNUM','CHECKNUM','FITID','RUNNINGBAL'])) {
                $extensions[$name] = trim((string)$child);
            }
        }

        return new OfxTransaction(
            $dt,
            $amt,
            trim((string)$trn->NAME),
            $memo,
            $trnType,
            trim((string)$trn->REFNUM),
            trim((string)$trn->CHECKNUM),
            trim((string)$trn->FITID),
            $raw,
            $extensions,
            $line,
            $byte
        );
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
