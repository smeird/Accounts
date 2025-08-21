<?php
// Parses OFX data using SimpleXML and validates required elements.
require_once __DIR__ . '/Account.php';
require_once __DIR__ . '/Ledger.php';
require_once __DIR__ . '/Transaction.php';

use Ofx\Account as OfxAccount;
use Ofx\Ledger as OfxLedger;
use Ofx\Transaction as OfxTransaction;

class OfxParser {
    public static function parse(string $data): array {
        $pos = strpos($data, '<OFX');
        if ($pos === false) {
            throw new Exception('Missing <OFX> root element');
        }
        $data = substr($data, $pos);
        // Convert SGML-style tags (<TAG>value) to XML by closing tags on new lines.
        $data = preg_replace("/<([^>\s]+)>([^<\r\n]+)\r?\n/", "<$1>$2</$1>\n", $data);
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($data, 'SimpleXMLElement', LIBXML_NOERROR | LIBXML_NOWARNING);
        if (!$xml) {
            throw new Exception('Failed to parse OFX');
        }

        $account = self::parseAccount($xml);
        $ledger = self::parseLedger($xml);
        $transactions = self::parseTransactions($xml);

        return [
            'account' => $account,
            'ledger' => $ledger,
            'transactions' => $transactions,
        ];
    }

    private static function parseAccount(SimpleXMLElement $xml): OfxAccount {
        $acctNode = $xml->xpath('//BANKACCTFROM | //CCACCTFROM | //ACCTFROM');
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

        return new OfxAccount($sortCode, $accountNumber, $accountName);
    }
    
    private static function parseLedger(SimpleXMLElement $xml): ?OfxLedger {
        $ledgerNode = $xml->xpath('//LEDGERBAL');
        if ($ledgerNode) {
            $balAmt = trim((string)$ledgerNode[0]->BALAMT);
            $dtAsOf = substr(trim((string)$ledgerNode[0]->DTASOF), 0, 8);
            if ($balAmt !== '' && $dtAsOf !== '') {
                return new OfxLedger((float)$balAmt, date('Y-m-d', strtotime($dtAsOf)));
            }
        }
        return null;
    }

    private static function parseTransactions(SimpleXMLElement $xml): array {
        $stmtTrns = $xml->xpath('//STMTTRN');
        if (!$stmtTrns) {
            throw new Exception('Missing STMTTRN');
        }
        $transactions = [];
        foreach ($stmtTrns as $trn) {
            $dateStr = substr(trim((string)$trn->DTPOSTED), 0, 8);
            $amountStr = trim((string)$trn->TRNAMT);
            if ($dateStr === '' || $amountStr === '') {
                throw new Exception('Missing DTPOSTED or TRNAMT');
            }
            $dt = DateTime::createFromFormat('Ymd', $dateStr);
            if (!$dt || $dt->format('Ymd') !== $dateStr) {
                throw new Exception('Invalid DTPOSTED value');
            }
            $transactions[] = new OfxTransaction(
                $dt->format('Y-m-d'),
                (float)$amountStr,
                (string)$trn->NAME,
                (string)$trn->MEMO,
                $trn->TRNTYPE ? strtoupper((string)$trn->TRNTYPE) : null,
                (string)$trn->REFNUM,
                (string)$trn->CHECKNUM,
                (string)$trn->FITID
            );
        }
        return $transactions;
    }
}
?>
