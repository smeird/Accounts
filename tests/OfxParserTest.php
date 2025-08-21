<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../php_backend/OfxParser.php';

class OfxParserTest extends TestCase
{
    public function testAccountExtraction(): void
    {
        $ofx = <<<OFX
<OFX>
<BANKACCTFROM>
<BANKID>123456</BANKID>
<ACCTID>12345678</ACCTID>
<ACCTNAME>Main</ACCTNAME>
</BANKACCTFROM>
<BANKTRANLIST>
<STMTTRN>
<DTPOSTED>20240101</DTPOSTED>
<TRNAMT>-10.00</TRNAMT>
</STMTTRN>
</BANKTRANLIST>
</OFX>
OFX;
        $parsed = OfxParser::parse($ofx);
        $this->assertSame('12345678', $parsed['account']->number);
        $this->assertSame('123456', $parsed['account']->sortCode);
        $this->assertSame('Main', $parsed['account']->name);
    }
}
