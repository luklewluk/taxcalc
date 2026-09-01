<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import\Importer;

use App\Import\CsvSource;
use App\Import\Importer\NormalizedDividendsImporter;
use App\Import\Importer\NormalizedPositionsImporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NormalizedPositionsImporter::class)]
#[CoversClass(NormalizedDividendsImporter::class)]
final class NormalizedImportersTest extends TestCase
{
    public function testPositionsCsvKeepsTheDocumentedColumnContract(): void
    {
        $content = "name,country,currency,buy_date,buy_total_amount,sell_date,sell_total_amount\n"
            ."Apple,US,USD,2020-05-04,71.8275,2020-12-16,127.4\n";

        $result = (new NormalizedPositionsImporter())->import(new CsvSource('positions.csv', $content));

        self::assertCount(1, $result->positions);

        $position = $result->positions[0];
        self::assertSame('Apple', $position->name);
        self::assertSame('US', $position->countryCode);
        self::assertSame('USD', $position->currency);
        self::assertSame('2020-05-04', $position->buyDate->format('Y-m-d'));
        // Sub-cent precision from the source file survives the import.
        self::assertSame('71.8275', (string) $position->buyAmount->value());
        self::assertSame('2020-12-16', $position->sellDate->format('Y-m-d'));
        self::assertSame('127.4', (string) $position->sellAmount->value());
    }

    public function testDividendsCsvKeepsTheDocumentedColumnContract(): void
    {
        $content = "name,country,currency,date,amount,tax_paid\n"
            ."Apple,US,USD,2020-08-13,0.82,0.12\n"
            ."Intel,US,USD,2020-11-12,1.00,0.30\n";

        $result = (new NormalizedDividendsImporter())->import(new CsvSource('dividends.csv', $content));

        self::assertCount(2, $result->dividends);
        self::assertSame('Apple', $result->dividends[0]->name);
        self::assertSame('0.82', (string) $result->dividends[0]->grossAmount->value());
        self::assertSame('0.12', (string) $result->dividends[0]->withheldTax->value());
    }

    public function testSubCentWithholdingIsNotRoundedAway(): void
    {
        $content = "name,country,currency,date,amount,tax_paid\nAAA,US,USD,2022-12-30,1.35,0.2025\n";

        $result = (new NormalizedDividendsImporter())->import(new CsvSource('d.csv', $content));

        self::assertSame('0.2025', (string) $result->dividends[0]->withheldTax->value());
    }

    public function testEmptyTaxPaidDefaultsToZero(): void
    {
        $content = "name,country,currency,date,amount,tax_paid\nAAA,IE,USD,2025-04-02,56.75,\n";

        $result = (new NormalizedDividendsImporter())->import(new CsvSource('d.csv', $content));

        self::assertSame('0', (string) $result->dividends[0]->withheldTax->value());
    }

    public function testPolishDecimalCommasAreAccepted(): void
    {
        $content = "name;country;currency;date;amount;tax_paid\nAAA;US;USD;2022-12-30;1,35;0,2025\n";

        $result = (new NormalizedDividendsImporter())->import(new CsvSource('d.csv', $content));

        self::assertCount(1, $result->dividends);
        self::assertSame('1.35', (string) $result->dividends[0]->grossAmount->value());
    }

    public function testUnknownCountryCodeIsAWarningNotAFailure(): void
    {
        $content = "name,country,currency,date,amount,tax_paid\nAAA,ZZ,USD,2022-12-30,1.35,0.20\n";

        $result = (new NormalizedDividendsImporter())->import(new CsvSource('d.csv', $content));

        self::assertCount(1, $result->dividends);
        self::assertNotEmpty($result->warnings());
        self::assertStringContainsString('ZZ', implode(' ', $result->warnings()));
    }

    public function testSellBeforeBuyIsReportedAsAnError(): void
    {
        $content = "name,country,currency,buy_date,buy_total_amount,sell_date,sell_total_amount\n"
            ."Apple,US,USD,2020-12-16,127.4,2020-05-04,71.8275\n";

        $result = (new NormalizedPositionsImporter())->import(new CsvSource('p.csv', $content));

        self::assertSame([], $result->positions);
        self::assertNotEmpty($result->errors());
    }

    public function testMissingColumnIsAFileLevelError(): void
    {
        $content = "name,country,currency,date\nApple,US,USD,2020-08-13\n";

        $result = (new NormalizedDividendsImporter())->import(new CsvSource('d.csv', $content));

        self::assertSame([], $result->dividends);
        self::assertNotEmpty($result->errors());
    }

    public function testMalformedRowsDoNotStopTheRestOfTheFile(): void
    {
        $content = "name,country,currency,date,amount,tax_paid\n"
            ."AAA,US,USD,nonsense,1.35,0.20\n"
            ."BBB,US,USD,2022-12-30,2.00,0.30\n";

        $result = (new NormalizedDividendsImporter())->import(new CsvSource('d.csv', $content));

        self::assertCount(1, $result->dividends);
        self::assertCount(1, $result->errors());
        self::assertStringContainsString('2', $result->errors()[0]);
    }
}
