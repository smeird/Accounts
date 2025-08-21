<?php
namespace Ofx;

// Represents the ledger balance reported in an OFX file.
class Ledger {
    public $balance;
    public $date;
    public $currency;

    public function __construct($balance, $date, $currency = 'GBP') {
        $this->balance = (float)$balance;
        $this->date = $date;
        $this->currency = $currency;
    }
}
?>
