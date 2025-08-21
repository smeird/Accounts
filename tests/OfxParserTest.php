<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../php_backend/OfxParser.php';

class OfxParserTest extends TestCase
{
    public function testAccountExtraction(): void
    {
        $ofx = <<<OFX
<OFX>
  <BANKMSGSRSV1>
    <STMTTRNRS>
      <STMTRS>
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
      </STMTRS>
    </STMTTRNRS>
  </BANKMSGSRSV1>
</OFX>
OFX;
        $parsed = OfxParser::parse($ofx);
        $this->assertSame('12345678', $parsed['account']->number);
        $this->assertSame('123456', $parsed['account']->sortCode);
        $this->assertSame('Main', $parsed['account']->name);
    }

    public function testLenientModePlaceholdersAndExtensions(): void
    {
        $ofx = <<<OFX
<OFX>
  <BANKMSGSRSV1>
    <STMTTRNRS>
      <STMTRS>
        <BANKACCTFROM>
          <ACCTID>1</ACCTID>
        </BANKACCTFROM>
        <BANKTRANLIST>
          <STMTTRN>
            <DTPOSTED>20240101</DTPOSTED>
            <TRNAMT>-1.00</TRNAMT>
            <UNKNOWN>foo</UNKNOWN>
          </STMTTRN>
        </BANKTRANLIST>
      </STMTRS>
    </STMTTRNRS>
  </BANKMSGSRSV1>
</OFX>
OFX;
        $parsed = OfxParser::parse($ofx, false);
        $this->assertSame('UNKNOWN', $parsed['transactions'][0]->type);
        $this->assertArrayHasKey('UNKNOWN', $parsed['transactions'][0]->extensions);
        $this->assertNotEmpty($parsed['warnings']);
    }

    public function testStrictModeThrowsOnMissingFields(): void
    {
        $this->expectException(Exception::class);
        $ofx = <<<OFX
<OFX>
  <BANKMSGSRSV1>
    <STMTTRNRS>
      <STMTRS>
        <BANKACCTFROM>
          <ACCTID>1</ACCTID>
        </BANKACCTFROM>
        <BANKTRANLIST>
          <STMTTRN>
            <DTPOSTED>20240101</DTPOSTED>
            <TRNAMT>-1.00</TRNAMT>
          </STMTTRN>
        </BANKTRANLIST>
      </STMTRS>
    </STMTTRNRS>
  </BANKMSGSRSV1>
</OFX>
OFX;
        OfxParser::parse($ofx, true);
    }
}
