<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../php_backend/OfxParser.php';
use Ofx\TransactionType;

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
        $parsed = OfxParser::parse($ofx)[0];
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
        $parsed = OfxParser::parse($ofx, false)[0];
        $this->assertSame(TransactionType::UNKNOWN, $parsed['transactions'][0]->type);
        $this->assertArrayHasKey('UNKNOWN', $parsed['transactions'][0]->extensions);
        $this->assertNotEmpty($parsed['warnings']);
    }

    public function testTrnTypeMappedToEnum(): void
    {
        $ofx = <<<OFX
<OFX>
  <BANKMSGSRSV1>
    <STMTTRNRS>
      <STMTRS>
        <BANKTRANLIST>
          <STMTTRN>
            <DTPOSTED>20240101</DTPOSTED>
            <TRNAMT>-10.00</TRNAMT>
            <TRNTYPE>DEBIT</TRNTYPE>
          </STMTTRN>
        </BANKTRANLIST>
      </STMTRS>
    </STMTTRNRS>
  </BANKMSGSRSV1>
</OFX>
OFX;
        $parsed = OfxParser::parse($ofx)[0];
        $this->assertSame(TransactionType::DEBIT, $parsed['transactions'][0]->type);
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

    public function testRunningBalanceMismatchAndDateWindowWarnings(): void

    {
        $ofx = <<<OFX
<OFX>
  <BANKMSGSRSV1>
    <STMTTRNRS>
      <STMTRS>

        <BANKACCTFROM><ACCTID>1</ACCTID></BANKACCTFROM>
        <BANKTRANLIST>
          <DTSTART>20240101</DTSTART>
          <DTEND>20240131</DTEND>
          <STMTTRN>
            <DTPOSTED>20240201</DTPOSTED>
            <TRNAMT>-1.00</TRNAMT>
            <RUNNINGBAL><BALAMT>100.00</BALAMT></RUNNINGBAL>
          </STMTTRN>
          <STMTTRN>
            <DTPOSTED>20240102</DTPOSTED>
            <TRNAMT>-1.00</TRNAMT>
            <RUNNINGBAL><BALAMT>98.00</BALAMT></RUNNINGBAL>
          </STMTTRN>

        </BANKTRANLIST>
      </STMTRS>
    </STMTTRNRS>
  </BANKMSGSRSV1>
</OFX>
OFX;
        $parsed = OfxParser::parse($ofx)[0];

        $this->assertNotEmpty($parsed['warnings']);
    }

    public function testDateClampingProducesWarnings(): void

    {
        $ofx = <<<OFX
<OFX>
  <BANKMSGSRSV1>
    <STMTTRNRS>
      <STMTRS>

        <BANKACCTFROM><ACCTID>1</ACCTID></BANKACCTFROM>
        <BANKTRANLIST>
          <STMTTRN>
            <DTPOSTED>18000101</DTPOSTED>
            <TRNAMT>-1.00</TRNAMT>
          </STMTTRN>
          <STMTTRN>
            <DTPOSTED>22001231</DTPOSTED>
            <TRNAMT>1.00</TRNAMT>
          </STMTTRN>
        </BANKTRANLIST>

      </STMTRS>
    </STMTTRNRS>
  </BANKMSGSRSV1>
</OFX>
OFX;
        $parsed = OfxParser::parse($ofx)[0];

        $this->assertSame('1900-01-01', $parsed['transactions'][0]->date);
        $this->assertSame('2100-12-31', $parsed['transactions'][1]->date);
        $this->assertNotEmpty($parsed['warnings']);

    }
}
