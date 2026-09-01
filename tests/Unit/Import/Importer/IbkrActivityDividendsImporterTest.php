<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import\Importer;

use App\Import\CsvSource;
use App\Import\Importer\IbkrActivityDividendsImporter;
use App\Import\ImportResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IbkrActivityDividendsImporter::class)]
final class IbkrActivityDividendsImporterTest extends TestCase
{
    private const string HEADER = '"CurrencyPrimary","Symbol","Multiplier","Date/Time","Amount","Type","TransactionID"';

    public function testImportsDividendRows(): void
    {
        $result = $this->import(['"USD","VUSD","1","20250402;202000","56.75","Dividends","1"']);

        self::assertCount(1, $result->dividends);

        $dividend = $result->dividends[0];
        self::assertSame('VUSD', $dividend->name);
        self::assertSame('USD', $dividend->currency);
        self::assertSame('2025-04-02', $dividend->date->format('Y-m-d'));
        self::assertSame('56.75', (string) $dividend->grossAmount->value());
        self::assertSame('0', (string) $dividend->withheldTax->value());
    }

    public function testWithholdingTaxRowsAreMatchedToTheirDividend(): void
    {
        $result = $this->import([
            '"USD","AAA","1","20250402;202000","100.00","Dividends","1"',
            '"USD","AAA","1","20250402;202000","-15.00","Withholding Tax","2"',
        ]);

        self::assertCount(1, $result->dividends);
        self::assertSame('100.00', (string) $result->dividends[0]->grossAmount->value());
        self::assertSame('15.00', (string) $result->dividends[0]->withheldTax->value());
    }

    public function testWithholdingTaxWithoutAMatchingDividendIsWarnedAbout(): void
    {
        $result = $this->import(['"USD","AAA","1","20250402;202000","-15.00","Withholding Tax","1"']);

        self::assertSame([], $result->dividends);
        self::assertCount(1, $result->warnings());
        self::assertStringContainsString('AAA', $result->warnings()[0]);
    }

    public function testNonDividendRowsAreIgnoredWithAnInformationalMessage(): void
    {
        $result = $this->import([
            '"EUR","","0","20240322","100","Deposits/Withdrawals","1"',
            '"EUR","","0","20240403","1900","Deposits/Withdrawals","2"',
            '"USD","AAA","1","20250402;202000","10.00","Dividends","3"',
        ]);

        self::assertCount(1, $result->dividends);
        self::assertNotEmpty($result->infos());
        self::assertStringContainsString('Deposits/Withdrawals', implode(' ', $result->infos()));
    }

    public function testCountryIsBlankWithAWarningBecauseTheFormatDoesNotCarryIt(): void
    {
        $result = $this->import(['"USD","AAA","1","20250402;202000","10.00","Dividends","1"']);

        self::assertSame('', $result->dividends[0]->countryCode);
        self::assertNotEmpty($result->warnings());
    }

    public function testMalformedAmountIsReportedPerRow(): void
    {
        $result = $this->import([
            '"USD","AAA","1","20250402;202000","n/a","Dividends","1"',
            '"USD","BBB","1","20250402;202000","10.00","Dividends","2"',
        ]);

        self::assertCount(1, $result->dividends);
        self::assertCount(1, $result->errors());
    }

    public function testPaymentInLieuOfDividendIsTreatedAsADividend(): void
    {
        $result = $this->import(['"USD","AAA","1","20250402;202000","5.00","Payment In Lieu Of Dividends","1"']);

        self::assertCount(1, $result->dividends);
        self::assertSame('5.00', (string) $result->dividends[0]->grossAmount->value());
    }

    /**
     * @param list<string> $rows
     */
    private function import(array $rows): ImportResult
    {
        $content = self::HEADER."\n".implode("\n", $rows)."\n";

        return (new IbkrActivityDividendsImporter())->import(new CsvSource('dividends.csv', $content));
    }
}
