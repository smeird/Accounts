<?php
namespace Ofx;

// Simple enumeration of transaction types represented as string constants.
// Using a class with constants keeps compatibility with PHP 7.x while
// providing namespaced values for mapping TRNTYPE codes.
class TransactionType
{
    const CREDIT      = 'CREDIT';
    const DEBIT       = 'DEBIT';
    const INT         = 'INT';
    const DIV         = 'DIV';
    const FEE         = 'FEE';
    const SRVCHG      = 'SRVCHG';
    const DEP         = 'DEP';
    const ATM         = 'ATM';
    const POS         = 'POS';
    const XFER        = 'XFER';
    const CHECK       = 'CHECK';
    const PAYMENT     = 'PAYMENT';
    const CASH        = 'CASH';
    const DIRECTDEP   = 'DIRECTDEP';
    const DIRECTDEBIT = 'DIRECTDEBIT';
    const REPEATPMT   = 'REPEATPMT';
    const HOLD        = 'HOLD';
    const OTHER       = 'OTHER';
    const UNKNOWN     = 'UNKNOWN';
}

?>

