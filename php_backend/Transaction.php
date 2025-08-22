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
    public $raw;
    public $extensions;
    public $line;
    public $offset;

    public function __construct($date, $amount, $desc = '', $memo = '', $type = null, $ref = '', $check = '', $bankId = '', $raw = '', array $extensions = [], ?int $line = null, ?int $offset = null) {
        $this->date = $date;
        $this->amount = (float)$amount;
        $this->desc = $desc;
        $this->memo = $memo;
        $this->type = $type;
        $this->ref = $ref;
        $this->check = $check;
        $this->bankId = $bankId;
        $this->raw = $raw;
        $this->extensions = $extensions;
        $this->line = $line;
        $this->offset = $offset;
    }
}
?>
