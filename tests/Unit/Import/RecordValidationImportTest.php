<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Fifo\FifoMatcher;
use App\Import\CsvSource;
use App\Import\Importer\IbkrActivityDividendsImporter;
use App\Import\Importer\IbkrDividendDetailImporter;
use App\Import\Importer\IbkrTradesImporter;
use App\Import\Importer\NormalizedDividendsImporter;
use App\Import\Importer\NormalizedPositionsImporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Untrusted files must never produce a record that silently becomes taxable
 * income of the wrong sign, and a malformed country code must not reach the
 * calculator.
 */
#[CoversClass(NormalizedDividendsImporter::class)]
#[CoversClass(NormalizedPositionsImporter::class)]
#[CoversClass(IbkrDividendDetailImporter::class)]
#[CoversClass(IbkrActivityDividendsImporter::class)]
final class RecordValidationImportTest extends TestCase
{
    public function testNormalizedDividendWithNegativeGrossIsRejectedNotFlipped(): void
    {
        $result = (new NormalizedDividendsImporter())->import(new CsvSource(
            'd.csv',
            "name,country,currency,date,amount,tax_paid\nAAA,US,USD,2025-04-02,-100.00,15.00\n",
        ));

        self::assertSame([], $result->dividends);
        self::assertCount(1, $result->errors());
        self::assertMatchesRegularExpression('/dodatni/iu', $result->errors()[0]);
    }

    public function testNormalizedDividendWithZeroGrossIsRejected(): void
    {
        $result = (new NormalizedDividendsImporter())->import(new CsvSource(
            'd.csv',
            "name,country,currency,date,amount,tax_paid\nAAA,US,USD,2025-04-02,0,0\n",
        ));

        self::assertSame([], $result->dividends);
        self::assertCount(1, $result->errors());
    }

    public function testNormalizedDividendNegativeWithheldTaxIsRejected(): void
    {
        $result = (new NormalizedDividendsImporter())->import(new CsvSource(
            'd.csv',
            "name,country,currency,date,amount,tax_paid\nAAA,US,USD,2025-04-02,100.00,-15.00\n",
        ));

        self::assertSame([], $result->dividends);
        self::assertNotEmpty($result->errors());
    }

    public function testNormalizedPositionWithNegativeAmountsIsRejected(): void
    {
        $result = (new NormalizedPositionsImporter())->import(new CsvSource(
            'p.csv',
            "name,country,currency,buy_date,buy_total_amount,sell_date,sell_total_amount\n"
            ."AAA,US,USD,2024-01-01,-10.00,2024-06-01,15.00\n"
            ."BBB,US,USD,2024-01-01,10.00,2024-06-01,-15.00\n"
            ."CCC,US,USD,2024-01-01,0,2024-06-01,15.00\n",
        ));

        self::assertSame([], $result->positions);
        self::assertCount(3, $result->errors());
    }

    public function testMalformedNonBlankCountryCodeIsRejectedOnImport(): void
    {
        $result = (new NormalizedDividendsImporter())->import(new CsvSource(
            'd.csv',
            "name,country,currency,date,amount,tax_paid\n"
            ."AAA,USA,USD,2025-04-02,100.00,15.00\n"
            ."BBB,U1,USD,2025-04-02,100.00,15.00\n"
            ."CCC,U,USD,2025-04-02,100.00,15.00\n",
        ));

        self::assertSame([], $result->dividends);
        self::assertCount(3, $result->errors());
        self::assertMatchesRegularExpression('/kod kraju/iu', implode(' ', $result->errors()));
    }

    public function testSyntacticallyValidButUnknownCountryStillImportsWithAWarning(): void
    {
        $result = (new NormalizedDividendsImporter())->import(new CsvSource(
            'd.csv',
            "name,country,currency,date,amount,tax_paid\nAAA,ZZ,USD,2025-04-02,100.00,15.00\n",
        ));

        self::assertCount(1, $result->dividends);
        self::assertSame([], $result->errors());
        self::assertNotEmpty($result->warnings());
    }

    public function testBlankCountryIsAcceptedOnImportSoIbkrFilesCanReachReview(): void
    {
        $result = (new NormalizedDividendsImporter())->import(new CsvSource(
            'd.csv',
            "name,country,currency,date,amount,tax_paid\nAAA,,USD,2025-04-02,100.00,15.00\n",
        ));

        self::assertCount(1, $result->dividends);
        self::assertSame('', $result->dividends[0]->countryCode);
        self::assertSame([], $result->errors());
    }

    public function testIbkrDividendDetailNegativeGrossIsRejected(): void
    {
        $result = (new IbkrDividendDetailImporter())->import(new CsvSource('detail.csv',
            "DividendDetail,Header,DataDiscriminator,Currency,Symbol,Conid,Country,ReportDate,ExDate,Shares,"
            ."RevenueComponent,QualifiedIndicator,Gross,GrossInBase,GrossInUSD,Withhold,WithholdInBase,WithholdInUSD\n"
            ."DividendDetail,Data,Summary,USD,AAA,1,US,20250213,20250207,100,,,-82.00,1,1,-12.30,-11.28,-12.30,\n",
        ));

        self::assertSame([], $result->dividends);
        self::assertCount(1, $result->errors());
    }

    public function testIbkrDividendDetailNegativeWithholdingIsStillNormalisedToAPositiveMagnitude(): void
    {
        $result = (new IbkrDividendDetailImporter())->import(new CsvSource('detail.csv',
            "DividendDetail,Header,DataDiscriminator,Currency,Symbol,Conid,Country,ReportDate,ExDate,Shares,"
            ."RevenueComponent,QualifiedIndicator,Gross,GrossInBase,GrossInUSD,Withhold,WithholdInBase,WithholdInUSD\n"
            ."DividendDetail,Data,Summary,USD,AAA,1,US,20250213,20250207,100,,,82.00,1,1,-12.30,-11.28,-12.30,\n",
        ));

        self::assertCount(1, $result->dividends);
        self::assertSame('12.30', (string) $result->dividends[0]->withheldTax->value());
    }

    public function testIbkrActivityNegativeDividendAmountIsRejected(): void
    {
        $result = (new IbkrActivityDividendsImporter())->import(new CsvSource('a.csv',
            '"CurrencyPrimary","Symbol","Multiplier","Date/Time","Amount","Type","TransactionID"'."\n"
            .'"USD","AAA","1","20250402;202000","-56.75","Dividends","1"'."\n",
        ));

        self::assertSame([], $result->dividends);
        self::assertCount(1, $result->errors());
    }

    public function testIbkrActivityWithholdingRowKeepsItsNegativeSourceSignAndBecomesPositive(): void
    {
        $result = (new IbkrActivityDividendsImporter())->import(new CsvSource('a.csv',
            '"CurrencyPrimary","Symbol","Multiplier","Date/Time","Amount","Type","TransactionID"'."\n"
            .'"USD","AAA","1","20250402;202000","100.00","Dividends","1"'."\n"
            .'"USD","AAA","1","20250402;202000","-15.00","Withholding Tax","2"'."\n",
        ));

        self::assertCount(1, $result->dividends);
        self::assertSame('100.00', (string) $result->dividends[0]->grossAmount->value());
        self::assertSame('15.00', (string) $result->dividends[0]->withheldTax->value());
    }

    public function testIbkrActivityPositiveWithholdingRefundReducesTaxInsteadOfIncreasingIt(): void
    {
        $result = (new IbkrActivityDividendsImporter())->import(new CsvSource('a.csv',
            '"CurrencyPrimary","Symbol","Multiplier","Date/Time","Amount","Type","TransactionID"'."\n"
            .'"USD","AAA","1","20250402;202000","100.00","Dividends","1"'."\n"
            .'"USD","AAA","1","20250402;202000","-15.00","Withholding Tax","2"'."\n"
            .'"USD","AAA","1","20250402;202000","5.00","Withholding Tax","3"'."\n",
        ));

        self::assertCount(1, $result->dividends);
        self::assertSame('10.00', (string) $result->dividends[0]->withheldTax->value());
    }

    public function testIbkrDividendDetailPositiveWithholdingIsRejectedAsARefund(): void
    {
        $result = (new IbkrDividendDetailImporter())->import(new CsvSource('detail.csv',
            "DividendDetail,Header,DataDiscriminator,Currency,Symbol,Conid,Country,ReportDate,ExDate,Shares,"
            ."RevenueComponent,QualifiedIndicator,Gross,GrossInBase,GrossInUSD,Withhold,WithholdInBase,WithholdInUSD\n"
            ."DividendDetail,Data,Summary,USD,AAA,1,US,20250213,20250207,100,,,82.00,1,1,5.00,1,1,\n",
        ));

        self::assertSame([], $result->dividends);
        self::assertNotEmpty($result->errors());
    }

    public function testTradeRowsProducingAZeroValuePositionAreReportedNotImported(): void
    {
        // A sell with zero net cash would create a position with zero proceeds.
        $importer = new IbkrTradesImporter(new FifoMatcher());
        $result = $importer->import(new CsvSource('t.csv',
            '"AssetClass","Symbol","TradeDate","Quantity","TradePrice","NetCash","TransactionID","CurrencyPrimary"'."\n"
            .'"STK","AAA","20240101","1","10","-10.00","1","USD"'."\n"
            .'"STK","AAA","20240601","-1","0","0.00","2","USD"'."\n",
        ));

        self::assertSame([], $result->positions);
        self::assertNotEmpty($result->errors());
    }

    public function testOneBadRowDoesNotStopTheOthers(): void
    {
        $result = (new NormalizedDividendsImporter())->import(new CsvSource(
            'd.csv',
            "name,country,currency,date,amount,tax_paid\n"
            ."BAD,US,USD,2025-04-02,-100.00,15.00\n"
            ."GOOD,US,USD,2025-04-02,100.00,15.00\n",
        ));

        self::assertCount(1, $result->dividends);
        self::assertSame('GOOD', $result->dividends[0]->name);
        self::assertCount(1, $result->errors());
        self::assertStringContainsString('2', $result->errors()[0]);
    }
}
