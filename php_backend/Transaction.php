<?php
namespace Ofx;

// Lightweight value object for a single transaction parsed from OFX.
class Transaction {
    public $date;
    public $amount;
    public $desc;
    public $memo;
    public $type;
    public $ref;
    public $check;
    public $bankId;

    public function __construct($date, $amount, $desc = '', $memo = '', $type = null, $ref = '', $check = '', $bankId = '') {
        $this->date = $date;
        $this->amount = (float)$amount;
        $this->desc = $desc;
        $this->memo = $memo;
        $this->type = $type;
        $this->ref = $ref;
        $this->check = $check;
        $this->bankId = $bankId;
    }
}
?>
