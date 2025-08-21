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

    public function testCurrencyMappingAndDefault(): void
    {
        $ofx = <<<OFX
<OFX>
  <BANKMSGSRSV1>
    <STMTTRNRS>
      <STMTRS>
        <CURDEF>UKL</CURDEF>
        <BANKACCTFROM><ACCTID>1</ACCTID></BANKACCTFROM>
        <BANKTRANLIST>
          <STMTTRN><DTPOSTED>20240101</DTPOSTED><TRNAMT>-1</TRNAMT></STMTTRN>
        </BANKTRANLIST>
      </STMTRS>
    </STMTTRNRS>
  </BANKMSGSRSV1>
</OFX>
OFX;
        $parsed = OfxParser::parse($ofx);
        $this->assertSame('GBP', $parsed['account']->currency);

        $ofxNoCur = <<<OFX
<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS>
<BANKACCTFROM><ACCTID>1</ACCTID></BANKACCTFROM>
<BANKTRANLIST><STMTTRN><DTPOSTED>20240101</DTPOSTED><TRNAMT>-1</TRNAMT></STMTTRN></BANKTRANLIST>
</STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>
OFX;
        $parsed2 = OfxParser::parse($ofxNoCur);
        $this->assertSame('GBP', $parsed2['account']->currency);
    }

    public function testAmountNormalisationAndClamping(): void
    {
        $ofx = <<<OFX
<OFX>
  <BANKMSGSRSV1>
    <STMTTRNRS>
      <STMTRS>
        <CURDEF>USD</CURDEF>
        <BANKACCTFROM><ACCTID>1</ACCTID></BANKACCTFROM>
        <BANKTRANLIST>
          <STMTTRN>
            <DTPOSTED>20240101</DTPOSTED>
            <TRNAMT>1,234 567.89-</TRNAMT>
          </STMTTRN>
        </BANKTRANLIST>
        <LEDGERBAL>
          <BALAMT>9999999999999999</BALAMT>
          <DTASOF>20240101</DTASOF>
        </LEDGERBAL>
      </STMTRS>
    </STMTTRNRS>
  </BANKMSGSRSV1>
</OFX>
OFX;
        $parsed = OfxParser::parse($ofx);
        $this->assertEquals(-1234567.89, $parsed['transactions'][0]->amount, '', 0.001);
        $this->assertEquals(999999999999.99, $parsed['ledger']->balance, '', 0.01);
    }
}
