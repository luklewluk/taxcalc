<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Fifo\FifoMatcher;
use App\Import\CsvImportService;
use App\Import\CsvSource;
use App\Import\FormatDetector;
use App\Import\Importer\DegiroAccountImporter;
use App\Import\Importer\DegiroTransactionsImporter;
use App\Import\Importer\IbkrActivityStatementImporter;
use App\Import\ImportResult;
use App\Import\MessageLevel;
use App\Tax\TaxRates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Untrusted files must never produce a record that silently becomes taxable
 * income of the wrong sign, and a malformed country code must not reach the
 * calculator.
 */
#[CoversClass(DegiroAccountImporter::class)]
#[CoversClass(DegiroTransactionsImporter::class)]
#[CoversClass(IbkrActivityStatementImporter::class)]
final class RecordValidationImportTest extends TestCase
{
    private const string ACCOUNT_HEADER = 'Date,Time,Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id';

    private const string TRADES_HEADER = 'Date,Time,Product,ISIN,Reference,Venue,Quantity,Price,,Local value,,Value,,'
        .'Exchange rate,Transaction and/or third party costs,,Total,,Order ID';

    /**
     * A negative payment is a reversal of an earlier one: it is skipped and
     * reported, never turned into a positive dividend of this year.
     */
    public function testDegiroNegativeGrossIsNotFlippedIntoIncome(): void
    {
        $result = self::account([
            '02-04-2025,06:32,02-04-2025,AAA,US000AAAA001,Dividend,,USD,-100.00,USD,0.00,',
        ]);

        self::assertSame([], $result->dividends);
        self::assertSame([], $result->errors());
        self::assertStringContainsString('storn', implode(' ', self::review($result)));
    }

    public function testDegiroZeroGrossNeverBecomesADividend(): void
    {
        $alone = self::account([
            '02-04-2025,06:32,02-04-2025,AAA,US000AAAA001,Dividend,,USD,0.00,USD,0.00,',
        ]);

        self::assertSame([], $alone->dividends);

        $withTax = self::account([
            '02-04-2025,06:32,02-04-2025,AAA,US000AAAA001,Dividend,,USD,0.00,USD,0.00,',
            '02-04-2025,06:32,02-04-2025,AAA,US000AAAA001,Dividend Tax,,USD,-15.00,USD,-15.00,',
        ]);

        self::assertSame([], $withTax->dividends);
        self::assertCount(1, $withTax->errors());
    }

    /**
     * DEGIRO books withholding as a negative charge; a positive balance would
     * mean more refunded than withheld, and must not become a negative credit.
     */
    public function testDegiroWithholdingOfTheWrongSignIsRejected(): void
    {
        $result = self::account([
            '02-04-2025,06:32,02-04-2025,AAA,US000AAAA001,Dividend,,USD,100.00,USD,100.00,',
            '02-04-2025,06:32,02-04-2025,AAA,US000AAAA001,Dividend Tax,,USD,15.00,USD,115.00,',
        ]);

        self::assertSame([], $result->dividends);
        self::assertNotEmpty($result->errors());
    }

    public function testDegiroTradeCashOfTheWrongSignOrMissingIsRejected(): void
    {
        $result = (new DegiroTransactionsImporter(new FifoMatcher()))->import(new CsvSource('t.csv', self::TRADES_HEADER."\n"
            // A buy that brought cash in.
            ."01-01-2024,10:00,AAA,XS000AAAA001,,,1,10.00,USD,10.00,USD,10.00,USD,,,,10.00,USD,t-1\n"
            ."01-06-2024,10:00,AAA,XS000AAAA001,,,-1,15.00,USD,15.00,USD,15.00,USD,,,,15.00,USD,t-2\n"
            // A sell that cost cash.
            ."01-01-2024,11:00,BBB,XS000BBBB002,,,1,10.00,USD,-10.00,USD,-10.00,USD,,,,-10.00,USD,t-3\n"
            ."01-06-2024,11:00,BBB,XS000BBBB002,,,-1,15.00,USD,-15.00,USD,-15.00,USD,,,,-15.00,USD,t-4\n"
            // A buy that cost nothing.
            ."01-01-2024,12:00,CCC,XS000CCCC003,,,1,0.00,USD,0.00,USD,0.00,USD,,,,0.00,USD,t-5\n"
            ."01-06-2024,12:00,CCC,XS000CCCC003,,,-1,15.00,USD,15.00,USD,15.00,USD,,,,15.00,USD,t-6\n"));

        self::assertSame([], $result->positions);
        self::assertCount(3, $result->errors());
    }

    /**
     * The country is inferred from the ISIN prefix, so an ISIN of the wrong
     * shape is refused rather than read as a country.
     */
    public function testMalformedIsinIsRejectedOnImportSoNoMalformedCountryIsInferred(): void
    {
        $result = self::account([
            '02-04-2025,06:32,02-04-2025,AAA,U1000AAAA001,Dividend,,USD,100.00,USD,100.00,',
            '02-04-2025,06:32,02-04-2025,BBB,US000BBBB02,Dividend,,USD,100.00,USD,200.00,',
            '02-04-2025,06:32,02-04-2025,CCC,US000CCCC00X,Dividend,,USD,100.00,USD,300.00,',
        ]);

        self::assertSame([], $result->dividends);
        self::assertCount(3, $result->errors());
        self::assertStringContainsString('ISIN', implode(' ', $result->errors()));
    }

    public function testSyntacticallyValidButUnknownCountryStillImportsWithAWarning(): void
    {
        $result = (new CsvImportService(new FormatDetector(), [new DegiroAccountImporter()], new TaxRates()))
            ->import([new CsvSource('d.csv', self::ACCOUNT_HEADER."\n"
                ."02-04-2025,06:32,02-04-2025,AAA,ZZ000AAAA001,Dividend,,USD,100.00,USD,100.00,\n"
                ."02-04-2025,06:32,02-04-2025,AAA,ZZ000AAAA001,Dividend Tax,,USD,-15.00,USD,85.00,\n")]);

        self::assertCount(1, $result->dividends);
        self::assertSame('ZZ', $result->dividends[0]->countryCode);
        self::assertSame([], $result->errors());
        self::assertStringContainsString('"ZZ"', implode(' ', $result->warnings()));
    }

    public function testBlankCountryIsAcceptedOnImportSoTheRowCanReachReview(): void
    {
        $result = self::account([
            '02-04-2025,06:32,02-04-2025,AAA,XS000AAAA001,Dividend,,USD,100.00,USD,100.00,',
            '02-04-2025,06:32,02-04-2025,AAA,XS000AAAA001,Dividend Tax,,USD,-15.00,USD,85.00,',
        ]);

        self::assertCount(1, $result->dividends);
        self::assertSame('', $result->dividends[0]->countryCode);
        self::assertSame([], $result->errors());
    }

    public function testActivityStatementNegativeDividendIsNotFlippedIntoIncome(): void
    {
        $result = self::statement(
            ['Dividends,Data,USD,2025-04-02,AAA(US000ALFA001) Cash Dividend USD 0.5675 per Share (Ordinary Dividend),-56.75'],
            [],
        );

        self::assertSame([], $result->dividends);
        self::assertSame([], $result->errors());
        self::assertStringContainsString('storn', implode(' ', self::review($result)));
    }

    public function testDegiroNegativeWithholdingIsNormalisedToAPositiveMagnitude(): void
    {
        $result = self::account([
            '13-02-2025,06:32,13-02-2025,AAA,US000AAAA001,Dividend,,USD,82.00,USD,82.00,',
            '13-02-2025,06:32,13-02-2025,AAA,US000AAAA001,Dividend Tax,,USD,-12.30,USD,69.70,',
        ]);

        self::assertCount(1, $result->dividends);
        self::assertSame('12.30', (string) $result->dividends[0]->withheldTax->value());
    }

    public function testActivityStatementWithholdingRowKeepsItsNegativeSourceSignAndBecomesPositive(): void
    {
        $result = self::statement(
            ['Dividends,Data,USD,2025-04-02,AAA(US000ALFA001) Cash Dividend USD 1.00 per Share (Ordinary Dividend),100.00'],
            ['Withholding Tax,Data,USD,2025-04-02,AAA(US000ALFA001) Cash Dividend USD 1.00 per Share - US Tax,-15.00,'],
        );

        self::assertCount(1, $result->dividends);
        self::assertSame('100.00', (string) $result->dividends[0]->grossAmount->value());
        self::assertSame('15.00', (string) $result->dividends[0]->withheldTax->value());
    }

    public function testActivityStatementPositiveWithholdingRefundReducesTaxInsteadOfIncreasingIt(): void
    {
        $result = self::statement(
            ['Dividends,Data,USD,2025-04-02,AAA(US000ALFA001) Cash Dividend USD 1.00 per Share (Ordinary Dividend),100.00'],
            [
                'Withholding Tax,Data,USD,2025-04-02,AAA(US000ALFA001) Cash Dividend USD 1.00 per Share - US Tax,-15.00,',
                'Withholding Tax,Data,USD,2025-04-02,AAA(US000ALFA001) Cash Dividend USD 1.00 per Share - US Tax,5.00,',
            ],
        );

        self::assertCount(1, $result->dividends);
        self::assertSame('10.00', (string) $result->dividends[0]->withheldTax->value());
    }

    /**
     * More refunded than withheld never becomes a credit. The Activity
     * Statement keeps the dividend with no credit at all - which can only
     * overstate the tax - and raises a review item.
     */
    public function testActivityStatementPositiveWithholdingNeverBecomesACredit(): void
    {
        $result = self::statement(
            ['Dividends,Data,USD,2025-02-13,AAA(US000ALFA001) Cash Dividend USD 0.82 per Share (Ordinary Dividend),82.00'],
            ['Withholding Tax,Data,USD,2025-02-13,AAA(US000ALFA001) Cash Dividend USD 0.82 per Share - US Tax,5.00,'],
        );

        self::assertCount(1, $result->dividends);
        self::assertTrue($result->dividends[0]->withheldTax->value()->isZero());
        self::assertSame([], $result->errors());
        self::assertNotSame([], self::review($result));
    }

    public function testTradeRowsProducingAZeroValuePositionAreReportedNotImported(): void
    {
        // A sell with zero settled cash would create a position with zero proceeds.
        $result = (new DegiroTransactionsImporter(new FifoMatcher()))->import(new CsvSource('t.csv', self::TRADES_HEADER."\n"
            ."01-01-2024,10:00,AAA,XS000AAAA001,,,1,10.00,USD,-10.00,USD,-10.00,USD,,,,-10.00,USD,t-1\n"
            ."01-06-2024,10:00,AAA,XS000AAAA001,,,-1,0.00,USD,0.00,USD,0.00,USD,,,,0.00,USD,t-2\n"));

        self::assertSame([], $result->positions);
        self::assertNotEmpty($result->errors());
    }

    public function testOneBadRowDoesNotStopTheOthers(): void
    {
        $result = self::account([
            '02-04-2025,06:32,02-04-2025,BAD,US000BAD,Dividend,,USD,100.00,USD,100.00,',
            '02-04-2025,06:32,02-04-2025,GOOD,US000GOOD001,Dividend,,USD,100.00,USD,200.00,',
        ]);

        self::assertCount(1, $result->dividends);
        self::assertSame('GOOD', $result->dividends[0]->name);
        self::assertCount(1, $result->errors());
        self::assertStringContainsString('wiersz 2', $result->errors()[0]);
    }

    /**
     * @param list<string> $rows
     */
    private static function account(array $rows): ImportResult
    {
        return (new DegiroAccountImporter())->import(
            new CsvSource('d.csv', self::ACCOUNT_HEADER."\n".implode("\n", $rows)."\n"),
        );
    }

    /**
     * An Activity Statement holding only dividends and withholding.
     *
     * @param list<string> $dividends
     * @param list<string> $withholding
     */
    private static function statement(array $dividends, array $withholding): ImportResult
    {
        $content = "Statement,Header,Field Name,Field Value\n"
            ."Statement,Data,Title,Activity Statement\n"
            ."Dividends,Header,Currency,Date,Description,Amount\n"
            .implode("\n", $dividends)."\n";

        if ([] !== $withholding) {
            $content .= "Withholding Tax,Header,Currency,Date,Description,Amount,Code\n"
                .implode("\n", $withholding)."\n";
        }

        return (new IbkrActivityStatementImporter(new FifoMatcher()))->import(new CsvSource('as.csv', $content));
    }

    /**
     * @return list<string>
     */
    private static function review(ImportResult $result): array
    {
        return array_values(array_map(
            static fn ($message): string => $message->message,
            array_filter($result->messages, static fn ($message): bool => MessageLevel::Review === $message->level),
        ));
    }
}
