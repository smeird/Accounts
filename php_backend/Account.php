<?php
namespace Ofx;

// Lightweight value object representing account details parsed from OFX files.
class Account {
    public $sortCode;
    public $number;
    public $name;

    public function __construct($sortCode, $number, $name) {
        $this->sortCode = $sortCode;
        $this->number = $number;
        $this->name = $name;
    }
}
?>
