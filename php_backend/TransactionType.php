<?php
namespace Ofx;

enum TransactionType: string {
    case CREDIT = 'CREDIT';
    case DEBIT = 'DEBIT';
    case INT = 'INT';
    case DIV = 'DIV';
    case FEE = 'FEE';
    case SRVCHG = 'SRVCHG';
    case DEP = 'DEP';
    case ATM = 'ATM';
    case POS = 'POS';
    case XFER = 'XFER';
    case CHECK = 'CHECK';
    case PAYMENT = 'PAYMENT';
    case CASH = 'CASH';
    case DIRECTDEP = 'DIRECTDEP';
    case DIRECTDEBIT = 'DIRECTDEBIT';
    case REPEATPMT = 'REPEATPMT';
    case HOLD = 'HOLD';
    case OTHER = 'OTHER';
    case UNKNOWN = 'UNKNOWN';
}
?>
