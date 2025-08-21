<?php
namespace Ofx;

// Represents the ledger balance reported in an OFX file.
class Ledger {
    public $balance;
    public $date;

    public function __construct($balance, $date) {
        $this->balance = (float)$balance;
        $this->date = $date;
    }
}
?>
