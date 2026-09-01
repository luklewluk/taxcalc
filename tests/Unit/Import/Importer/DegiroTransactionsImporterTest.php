<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import\Importer;

use App\Fifo\FifoMatcher;
use App\Import\CsvFormat;
use App\Import\CsvSource;
use App\Import\Importer\DegiroTransactionsImporter;
use App\Import\ImportResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DegiroTransactionsImporter::class)]
final class DegiroTransactionsImporterTest extends TestCase
{
    /**
     * The 16-column English layout, with the currency of every amount in the
     * unnamed column that follows it.
     */
    private const string HEADER_OLD_EN =
        'Date,Time,Product,ISIN,Reference Exchange,Execution Venue,Quantity,Price,,Value,,'
        .'Transaction and/or third,,Total,,Order ID';

    public function testSupportsOnlyItsOwnFormat(): void
    {
        $importer = self::importer();

        self::assertTrue($importer->supports(CsvFormat::DegiroTransactions));
        self::assertFalse($importer->supports(CsvFormat::DegiroAccount));
        self::assertFalse($importer->supports(CsvFormat::IbkrTrades));
    }

    public function testMatchesABuyAgainstASellAndUsesTheSettledTotalAsTheAmount(): void
    {
        $result = self::import([
            '15-03-2025,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,175.50,USD,-1755.00,USD,-1.00,USD,-1756.00,USD,aaa-111',
            '20-09-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,195.00,USD,1950.00,USD,-1.00,USD,1949.00,USD,bbb-222',
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->positions);

        $position = $result->positions[0];
        self::assertSame('ALFA CORP', $position->name);
        self::assertSame('USD', $position->currency);
        self::assertSame('2025-03-15', $position->buyDate->format('Y-m-d'));
        self::assertSame('2025-09-20', $position->sellDate->format('Y-m-d'));
        self::assertSame('10', (string) $position->quantity);

        // The transaction fee is part of DEGIRO's Total, so the cost basis is
        // 1755.00 + 1.00 and the proceeds are 1950.00 - 1.00.
        self::assertSame('1756.00', (string) $position->buyAmount->value());
        self::assertSame('1949.00', (string) $position->sellAmount->value());
    }

    public function testCountryIsInferredFromTheIsinPrefixAndFlaggedForReview(): void
    {
        $result = self::import([
            '15-03-2025,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,175.50,USD,-1755.00,USD,-1.00,USD,-1756.00,USD,aaa-111',
            '20-09-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,195.00,USD,1950.00,USD,-1.00,USD,1949.00,USD,bbb-222',
        ]);

        self::assertSame('US', $result->positions[0]->countryCode);

        $warnings = mb_strtolower(implode(' ', $result->warnings()));
        self::assertStringContainsString('isin', $warnings);
        self::assertStringContainsString('sprawdź', $warnings);
    }

    public function testIsinPrefixesThatAreNotCountriesLeaveTheCountryBlank(): void
    {
        // XS is Euroclear/Clearstream, not a jurisdiction.
        $result = self::import([
            '15-03-2025,09:15,DELTA BOND,XS000DELTA02,EAM,XAMS,10,175.50,USD,-1755.00,USD,-1.00,USD,-1756.00,USD,aaa-111',
            '20-09-2025,14:30,DELTA BOND,XS000DELTA02,EAM,XAMS,-10,195.00,USD,1950.00,USD,-1.00,USD,1949.00,USD,bbb-222',
        ]);

        self::assertCount(1, $result->positions);
        self::assertSame('', $result->positions[0]->countryCode);
    }

    public function testReadsTheContinentalNotationFromASemicolonExport(): void
    {
        $content = "Data;Czas;Produkt;ISIN;Giełda referenc;Miejsce wykonania;Liczba;Kurs;;Wartość lokalna;;"
            ."Wartość;;Kurs wymian;Opłata transakcyjna;;Razem;;Identyfikator zlecenia\n"
            ."15-03-2025;09:15;ALFA CORP;US000ALFA001;NDQ;XNAS;10;175,50;USD;-1.755,00;USD;-1.755,00;USD;;-1,00;USD;-1.756,00;USD;aaa-111\n"
            ."20-09-2025;14:30;ALFA CORP;US000ALFA001;NDQ;XNAS;-10;195,00;USD;1.950,00;USD;1.950,00;USD;;-1,00;USD;1.949,00;USD;bbb-222\n";

        $result = self::importer()->import(new CsvSource('degiro.csv', $content));

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->positions);
        self::assertSame('1756.00', (string) $result->positions[0]->buyAmount->value());
        self::assertSame('1949.00', (string) $result->positions[0]->sellAmount->value());
    }

    /**
     * A continental amount below a thousand has nothing to disambiguate it, so
     * the file's delimiter has to decide: a semicolon-separated DEGIRO export
     * uses the comma as its decimal separator. Reading `-95,50` as -9550 would
     * inflate a cost basis a hundredfold.
     */
    public function testASmallContinentalAmountIsNotReadAsThousands(): void
    {
        $content = "Data;Czas;Produkt;ISIN;Giełda referenc;Miejsce wykonania;Liczba;Kurs;;Wartość;;"
            ."Opłata transakcyjna;;Razem;;Identyfikator zlecenia\n"
            ."15-03-2025;09:15;ALFA CORP;US000ALFA001;NDQ;XNAS;1;95,00;USD;-95,00;USD;-0,50;USD;-95,50;USD;aaa-111\n"
            ."20-09-2025;14:30;ALFA CORP;US000ALFA001;NDQ;XNAS;-1;120,00;USD;120,00;USD;-0,50;USD;119,50;USD;bbb-222\n";

        $result = self::importer()->import(new CsvSource('degiro.csv', $content));

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->positions);
        self::assertSame('95.50', (string) $result->positions[0]->buyAmount->value());
        self::assertSame('119.50', (string) $result->positions[0]->sellAmount->value());
    }

    public function testReadsTheLayoutWhoseTotalHeaderCarriesTheCurrency(): void
    {
        $content = "Date,Time,Product,ISIN,Reference,Venue,Quantity,Price,,Local value,,Value,,Exchange rate,"
            ."Transaction and/or third party costs,,Total EUR,Order ID\n"
            ."02-01-2024,09:05,BETA ETF,IE000BETA002,EAM,XAMS,10,264.30,EUR,-2643.00,EUR,-2643.00,EUR,,-2.00,EUR,-2645.00,bbb-1\n"
            ."03-06-2025,10:05,BETA ETF,IE000BETA002,EAM,XAMS,-10,300.00,EUR,3000.00,EUR,3000.00,EUR,,-2.00,EUR,2998.00,bbb-2\n";

        $result = self::importer()->import(new CsvSource('degiro.csv', $content));

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->positions);
        self::assertSame('EUR', $result->positions[0]->currency);
        self::assertSame('2645.00', (string) $result->positions[0]->buyAmount->value());
        self::assertSame('2998.00', (string) $result->positions[0]->sellAmount->value());
        self::assertSame('IE', $result->positions[0]->countryCode);
    }

    public function testARenamedProductStillMatchesUnderTheSameIsinAndKeepsTheCurrentName(): void
    {
        $result = self::import([
            '15-03-2025,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,175.50,USD,-1755.00,USD,-1.00,USD,-1756.00,USD,aaa-111',
            '20-09-2025,14:30,ALFA GROUP PLC,US000ALFA001,NDQ,XNAS,-10,195.00,USD,1950.00,USD,-1.00,USD,1949.00,USD,bbb-222',
        ]);

        self::assertCount(1, $result->positions);
        self::assertSame('ALFA GROUP PLC', $result->positions[0]->name);
    }

    /**
     * One ISIN is one holding and therefore one queue, whichever venue and
     * currency each fill settled in. FIFO still takes the oldest lot: here that
     * is the USD one, and the sale settles against it.
     */
    public function testOneIsinIsOneQueueAcrossVenuesAndCurrencies(): void
    {
        $result = self::import([
            '15-03-2025,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,175.50,USD,-1755.00,USD,-1.00,USD,-1756.00,USD,a1',
            '16-03-2025,09:15,ALFA CORP,US000ALFA001,EAM,XAMS,10,160.00,EUR,-1600.00,EUR,-1.00,EUR,-1601.00,EUR,a2',
            '20-09-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,195.00,USD,1950.00,USD,-1.00,USD,1949.00,USD,a3',
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->positions);
        self::assertSame('USD', $result->positions[0]->currency);
        self::assertSame('1756.00', (string) $result->positions[0]->buyAmount->value());
    }

    /**
     * When the oldest lot settled in another currency the position cannot be
     * expressed at all - a closed position carries one currency - so it is
     * refused rather than settled in whichever currency came first.
     */
    public function testALotOpenedInAnotherCurrencyThanTheSaleIsRefused(): void
    {
        $result = self::import([
            '15-03-2025,09:15,ALFA CORP,US000ALFA001,EAM,XAMS,10,160.00,EUR,-1600.00,EUR,-1.00,EUR,-1601.00,EUR,a1',
            '20-09-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,195.00,USD,1950.00,USD,-1.00,USD,1949.00,USD,a2',
        ]);

        self::assertSame([], $result->positions);
        self::assertNotEmpty($result->errors());
        self::assertStringContainsString('EUR', implode(' ', $result->errors()));
        self::assertStringContainsString('USD', implode(' ', $result->errors()));
    }

    public function testZeroQuantityRowsAreSkippedWithANoteRatherThanTaxed(): void
    {
        $result = self::import([
            '15-03-2025,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,175.50,USD,-1755.00,USD,-1.00,USD,-1756.00,USD,aaa-111',
            '01-07-2025,00:00,ALFA CORP,US000ALFA001,NDQ,XNAS,0,0.00,USD,0.00,USD,0.00,USD,0.00,USD,',
        ]);

        self::assertSame([], $result->errors());
        self::assertSame([], $result->positions);
        self::assertStringContainsString('zerow', mb_strtolower(implode(' ', $result->infos())));
    }

    public function testZeroPriceRowsAreRefusedAsACorporateActionNeedingManualWork(): void
    {
        $result = self::import([
            '15-03-2025,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,175.50,USD,-1755.00,USD,-1.00,USD,-1756.00,USD,aaa-111',
            '01-07-2025,00:00,ALFA CORP,US000ALFA001,NDQ,XNAS,20,0.00,USD,0.00,USD,0.00,USD,0.00,USD,split-1',
        ]);

        // Never a taxable trade, and never silently dropped either: skipping it
        // would change the cost basis of every later sale of this instrument.
        self::assertSame([], $result->positions);

        $errors = mb_strtolower(implode(' ', $result->errors()));
        self::assertStringContainsString('korporacyjn', $errors);
        self::assertStringContainsString('ręcznie', $errors);
    }

    public function testABuyWithPositiveCashAbortsTheImportInsteadOfGuessing(): void
    {
        $result = self::import([
            '15-03-2025,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,175.50,USD,1755.00,USD,-1.00,USD,1756.00,USD,aaa-111',
        ]);

        self::assertSame([], $result->positions);
        self::assertNotEmpty($result->errors());
        self::assertStringContainsString('znak', mb_strtolower(implode(' ', $result->errors())));
    }

    public function testASellWithNegativeCashAbortsTheImportInsteadOfGuessing(): void
    {
        $result = self::import([
            '20-09-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,195.00,USD,-1950.00,USD,-1.00,USD,-1949.00,USD,bbb-222',
        ]);

        self::assertNotEmpty($result->errors());
    }

    public function testAMissingIsinIsAnErrorBecauseTheRowCannotBeIdentified(): void
    {
        $result = self::import([
            '15-03-2025,09:15,ALFA CORP,,NDQ,XNAS,10,175.50,USD,-1755.00,USD,-1.00,USD,-1756.00,USD,aaa-111',
        ]);

        self::assertNotEmpty($result->errors());
        self::assertStringContainsString('ISIN', implode(' ', $result->errors()));
    }

    /**
     * When the total is the last column and its header does not name a currency,
     * there is nowhere left for the currency to be. That is one problem with the
     * file, so it is reported once against the header rather than once per row.
     */
    public function testATotalColumnWithNowhereToPutItsCurrencyIsReportedOnce(): void
    {
        $content = "Date,Time,Product,ISIN,Reference,Venue,Quantity,Price,,Value,,Total\n"
            ."15-03-2025,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,175.50,USD,-1755.00,USD,-1756.00\n"
            ."20-09-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,195.00,USD,1950.00,USD,1949.00\n";

        $result = self::importer()->import(new CsvSource('degiro.csv', $content));

        self::assertSame([], $result->positions);
        self::assertCount(1, $result->errors());
        self::assertStringContainsString('walut', mb_strtolower(implode(' ', $result->errors())));
    }

    public function testARowWithTooFewColumnsIsAnErrorRatherThanAMisalignedTrade(): void
    {
        $result = self::import([
            '15-03-2025,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,175.50',
        ]);

        self::assertSame([], $result->positions);
        self::assertNotEmpty($result->errors());
    }

    /**
     * DEGIRO records the time of every execution, and without it a buy and a
     * sell settled on the same day can only be ordered by the order the files
     * happened to be uploaded in.
     */
    public function testTheExecutionTimeOrdersTradesSettledOnTheSameDay(): void
    {
        $result = self::import([
            '03-04-2024,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,150.00,USD,1500.00,USD,-1.00,USD,1499.00,USD,s1',
            '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,100.00,USD,-1000.00,USD,-1.00,USD,-1001.00,USD,b1',
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->positions);
        self::assertSame('1001.00', (string) $result->positions[0]->buyAmount->value());
        self::assertSame('1499.00', (string) $result->positions[0]->sellAmount->value());
    }

    /**
     * The time orders the queue; it must not leak into the settled record, where
     * the day is what picks the NBP rate and the tax year.
     */
    public function testTheExecutionTimeDoesNotLeakIntoTheSettledPosition(): void
    {
        $result = self::import([
            '15-03-2025,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,175.50,USD,-1755.00,USD,-1.00,USD,-1756.00,USD,aaa-111',
            '20-09-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,195.00,USD,1950.00,USD,-1.00,USD,1949.00,USD,bbb-222',
        ]);

        self::assertSame('00:00:00', $result->positions[0]->buyDate->format('H:i:s'));
        self::assertSame('00:00:00', $result->positions[0]->sellDate->format('H:i:s'));
    }

    public function testSecondsInTheTimeColumnAreAccepted(): void
    {
        $result = self::import([
            '03-04-2024,14:30:05,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,150.00,USD,1500.00,USD,-1.00,USD,1499.00,USD,s1',
            '03-04-2024,09:15:59,ALFA CORP,US000ALFA001,NDQ,XNAS,10,100.00,USD,-1000.00,USD,-1.00,USD,-1001.00,USD,b1',
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->positions);
    }

    #[DataProvider('invalidTimes')]
    public function testAMalformedTimeIsARowErrorRatherThanASilentMidnight(string $time): void
    {
        $result = self::import([
            sprintf(
                '03-04-2024,%s,ALFA CORP,US000ALFA001,NDQ,XNAS,10,100.00,USD,-1000.00,USD,-1.00,USD,-1001.00,USD,b1',
                $time,
            ),
        ]);

        self::assertSame([], $result->positions);
        self::assertNotEmpty($result->errors());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTimes(): iterable
    {
        yield 'hour 24' => ['24:00'];
        yield 'minute 60' => ['09:60'];
        yield 'second 60' => ['09:15:60'];
        yield 'not a time' => ['morning'];
        yield 'single field' => ['0915'];
        yield 'dotted' => ['09.15'];
    }

    public function testABlankTimeColumnIsStillAccepted(): void
    {
        $result = self::import([
            '15-03-2025,,ALFA CORP,US000ALFA001,NDQ,XNAS,10,175.50,USD,-1755.00,USD,-1.00,USD,-1756.00,USD,aaa-111',
            '20-09-2025,,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,195.00,USD,1950.00,USD,-1.00,USD,1949.00,USD,bbb-222',
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->positions);
    }

    /**
     * DEGIRO writes the time to the minute, so a buy and a sell inside one
     * minute cannot be ordered from the file. Their order is taken from the file
     * and the ambiguity is stated, rather than passed off as certain.
     */
    public function testABuyAndSellSharingATimestampIsFatalBecauseOrderIsUnknown(): void
    {
        $result = self::import([
            '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,100.00,USD,-1000.00,USD,-1.00,USD,-1001.00,USD,b1',
            '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,150.00,USD,1500.00,USD,-1.00,USD,1499.00,USD,s1',
        ]);

        self::assertSame([], $result->positions);
        self::assertMatchesRegularExpression(
            '/minut/u',
            implode(' ', $result->errors()),
        );
    }

    /**
     * When the file itself puts the sale first, the sale is first. It must not be
     * quietly reordered into a profitable long trade - it comes out uncovered,
     * which is fatal, and the user is told the minute is ambiguous.
     */
    public function testASaleListedBeforeItsBuyAtTheSameMinuteIsNotTurnedIntoALongTrade(): void
    {
        $result = self::import([
            '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,150.00,USD,1500.00,USD,-1.00,USD,1499.00,USD,s1',
            '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,100.00,USD,-1000.00,USD,-1.00,USD,-1001.00,USD,b1',
        ]);

        self::assertSame([], $result->positions);
        self::assertMatchesRegularExpression('/minut/u', implode(' ', $result->errors()));
    }

    public function testAnUnparseableDateIsARowErrorAndNotAnException(): void
    {
        $result = self::import([
            '32-03-2025,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,175.50,USD,-1755.00,USD,-1.00,USD,-1756.00,USD,aaa-111',
        ]);

        self::assertNotEmpty($result->errors());
    }

    /**
     * A sale with no purchase behind it has no cost basis, so its whole proceeds
     * would read as gain. That cannot be a warning next to a settled result.
     */
    public function testAnUncoveredSellIsAnErrorNotAWarning(): void
    {
        $result = self::import([
            '20-09-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,195.00,USD,1950.00,USD,-1.00,USD,1949.00,USD,bbb-222',
        ]);

        self::assertSame([], $result->positions);
        self::assertStringContainsString('nie ma pokrycia', implode(' ', $result->errors()));
    }

    public function testTheFileWithoutAnAutofxColumnSaysSoOnceInsteadOfInventingFees(): void
    {
        $result = self::import([
            '15-03-2025,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,175.50,USD,-1755.00,USD,-1.00,USD,-1756.00,USD,aaa-111',
            '20-09-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,195.00,USD,1950.00,USD,-1.00,USD,1949.00,USD,bbb-222',
        ]);

        $autofx = array_filter(
            [...$result->infos(), ...$result->warnings()],
            static fn (string $m): bool => str_contains(mb_strtolower($m), 'autofx'),
        );

        self::assertCount(1, $autofx);
    }

    public function testEveryPositionSaysWhichFileAndFormatItCameFrom(): void
    {
        $result = self::import([
            '15-03-2025,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,175.50,USD,-1755.00,USD,-1.00,USD,-1756.00,USD,aaa-111',
            '20-09-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,195.00,USD,1950.00,USD,-1.00,USD,1949.00,USD,bbb-222',
        ]);

        self::assertStringContainsString('degiro.csv', $result->positions[0]->source);
        self::assertStringContainsString('DEGIRO', $result->positions[0]->source);
    }

    /**
     * Spreadsheets re-save DEGIRO exports with a byte-order mark, which would
     * otherwise glue itself to the first header and lose the date column.
     */
    public function testAByteOrderMarkDoesNotHideTheFirstColumn(): void
    {
        $content = "\u{FEFF}".self::file([
            '15-03-2025,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,175.50,USD,-1755.00,USD,-1.00,USD,-1756.00,USD,aaa-111',
            '20-09-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,195.00,USD,1950.00,USD,-1.00,USD,1949.00,USD,bbb-222',
        ]);

        $result = self::importer()->import(new CsvSource('degiro.csv', $content));

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->positions);
        self::assertSame('2025-03-15', $result->positions[0]->buyDate->format('Y-m-d'));
    }

    public function testAnEmptyFileIsRejectedWithAMessage(): void
    {
        $result = self::importer()->import(new CsvSource('degiro.csv', ''));

        self::assertNotEmpty($result->errors());
    }

    /**
     * @param list<string> $rows
     */
    private static function import(array $rows): ImportResult
    {
        return self::importer()->import(new CsvSource('degiro.csv', self::file($rows)));
    }

    /**
     * @param list<string> $rows
     */
    private static function file(array $rows): string
    {
        return self::HEADER_OLD_EN."\n".implode("\n", $rows)."\n";
    }

    private static function importer(): DegiroTransactionsImporter
    {
        return new DegiroTransactionsImporter(new FifoMatcher());
    }
}
