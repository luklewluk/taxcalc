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
use App\Tax\TaxRates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Interactive Brokers statements are downloaded per year, so the buy leg of a
 * position routinely lives in a different file from the sell leg. FIFO must
 * therefore run once over every uploaded trade file, not once per file.
 */
#[CoversClass(CsvImportService::class)]
#[CoversClass(IbkrActivityStatementImporter::class)]
final class CrossSourceImportTest extends TestCase
{
    private const string TRADES_HEADER =
        'Trades,Header,DataDiscriminator,Asset Category,Currency,Symbol,Date/Time,Quantity,T. Price,C. Price,'
        .'Proceeds,Comm/Fee,Basis,Realized P/L,MTM P/L,Code';

    private const string INSTRUMENTS =
        "Financial Instrument Information,Header,Asset Category,Symbol,Description,Conid,Security ID,Underlying,"
        ."Listing Exch,Multiplier,Type,Code\n"
        ."Financial Instrument Information,Data,Stocks,AAA,ALFA CORP,1001,US000ALFA001,AAA,NASDAQ,1,COMMON,\n"
        ."Financial Instrument Information,Data,Stocks,BBB,BETA ETF,1002,IE000BETA002,BBB,LSEETF,1,ETF,\n";

    private const string BUY_2024 = 'Trades,Data,Order,Stocks,USD,AAA,"2024-01-02, 10:00:00",10,100,100,-1000,0,1000,0,0,O';

    private const string SELL_2025 = 'Trades,Data,Order,Stocks,USD,AAA,"2025-06-02, 10:00:00",-10,150,150,1500,0,-1000,500,0,C';

    public function testBuyInOneFileIsMatchedAgainstASellInAnother(): void
    {
        $result = $this->import([
            'as-2024.csv' => self::statement([self::BUY_2024]),
            'as-2025.csv' => self::statement([self::SELL_2025]),
        ]);

        self::assertCount(1, $result->positions, implode(' | ', $result->warnings()));

        $position = $result->positions[0];
        self::assertSame('2024-01-02', $position->buyDate->format('Y-m-d'));
        self::assertSame('2025-06-02', $position->sellDate->format('Y-m-d'));
        self::assertSame('1000.00', (string) $position->buyAmount->value());
        self::assertSame('1500.00', (string) $position->sellAmount->value());
        self::assertSame(2025, $position->taxYear());

        // Nothing may be reported as unmatched any more.
        self::assertStringNotContainsString('nie ma pokrycia', implode(' ', $result->warnings()));
    }

    public function testFileOrderDoesNotMatter(): void
    {
        $result = $this->import([
            'as-2025.csv' => self::statement([self::SELL_2025]),
            'as-2024.csv' => self::statement([self::BUY_2024]),
        ]);

        self::assertCount(1, $result->positions);
        self::assertSame('2024-01-02', $result->positions[0]->buyDate->format('Y-m-d'));
    }

    public function testOverlappingStatementsAreDeduplicatedBeforeMatching(): void
    {
        // Statements overlap: the 2025 file repeats the 2024 opening trade.
        $result = $this->import([
            'as-2024.csv' => self::statement([self::BUY_2024]),
            'as-2025.csv' => self::statement([self::BUY_2024, self::SELL_2025]),
        ]);

        // The repeated buy must not create a second lot and must not leave an
        // open position behind.
        self::assertCount(1, $result->positions);
        self::assertCount(2, $result->trades);
        self::assertSame('1000.00', (string) $result->positions[0]->buyAmount->value());
        self::assertSame('10', (string) $result->positions[0]->quantity);
    }

    public function testUploadingTheExactSameTradeFileTwiceChangesNothing(): void
    {
        $file = self::statement([self::BUY_2024, self::SELL_2025]);

        $once = $this->import(['a.csv' => $file]);
        $twice = $this->import(['a.csv' => $file, 'b.csv' => $file]);

        self::assertCount(1, $once->positions);
        self::assertCount(1, $twice->positions);
        self::assertSame(
            (string) $once->positions[0]->sellAmount->value(),
            (string) $twice->positions[0]->sellAmount->value(),
        );
    }

    public function testIdenticalFillsWithoutABrokerIdAreBothKept(): void
    {
        // Two economically identical fills at the same instant, and two
        // identical sells. These are four real trades, not duplicates.
        $result = $this->import([
            'as.csv' => self::statement(self::identicalFills()),
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(2, $result->positions);
        self::assertSame('10.00', (string) $result->positions[0]->buyAmount->value());
        self::assertSame('10.00', (string) $result->positions[1]->buyAmount->value());
    }

    public function testDistinctFillsSurviveEvenWhenTheSameFileIsUploadedTwice(): void
    {
        $file = self::statement(self::identicalFills());

        $result = $this->import(['a.csv' => $file, 'b.csv' => $file]);

        self::assertSame([], $result->errors());
        self::assertCount(2, $result->positions);
    }

    public function testTradesInDifferentCurrenciesStayIndependent(): void
    {
        $result = $this->import([
            'usd.csv' => self::statement([
                'Trades,Data,Order,Stocks,USD,AAA,"2024-01-02, 10:00:00",1,10,10,-10,0,10,0,0,O',
                'Trades,Data,Order,Stocks,USD,AAA,"2024-06-03, 10:00:00",-1,15,15,15,0,-10,5,0,C',
            ]),
            'eur.csv' => self::statement([
                'Trades,Data,Order,Stocks,EUR,AAA,"2024-01-03, 10:00:00",1,20,20,-20,0,20,0,0,O',
                'Trades,Data,Order,Stocks,EUR,AAA,"2024-06-04, 10:00:00",-1,25,25,25,0,-20,5,0,C',
            ]),
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(2, $result->positions);
        $currencies = array_map(static fn ($p): string => $p->currency, $result->positions);
        sort($currencies);
        self::assertSame(['EUR', 'USD'], $currencies);
    }

    /**
     * A sale whose purchase is in none of the uploaded files has no cost basis,
     * so it is left out and reported once, as a review item - the rest of the
     * batch still imports.
     */
    public function testUnmatchedSellAcrossAllFilesIsReportedOnceAndTheRestImports(): void
    {
        $result = $this->import([
            'a.csv' => self::statement(['Trades,Data,Order,Stocks,USD,AAA,"2025-06-02, 10:00:00",-5,15,15,75,0,-50,25,0,C']),
            'b.csv' => self::statement(['Trades,Data,Order,Stocks,USD,BBB,"2024-01-02, 10:00:00",1,10,10,-10,0,10,0,0,O']),
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(2, $result->trades, 'Every transaction reaches the workbench.');

        $unmatched = array_filter(
            $result->warnings(),
            static fn (string $e): bool => str_contains($e, 'nie ma pokrycia'),
        );
        self::assertCount(1, $unmatched);
        self::assertStringContainsString('AAA', implode(' ', $unmatched));
    }

    /**
     * @return list<string>
     */
    private static function identicalFills(): array
    {
        return [
            'Trades,Data,Order,Stocks,USD,AAA,"2024-01-02, 10:00:00",1,10,10,-10,0,10,0,0,O',
            'Trades,Data,Order,Stocks,USD,AAA,"2024-01-02, 10:00:00",1,10,10,-10,0,10,0,0,O',
            'Trades,Data,Order,Stocks,USD,AAA,"2024-06-03, 10:00:00",-1,15,15,15,0,-10,5,0,C',
            'Trades,Data,Order,Stocks,USD,AAA,"2024-06-03, 10:00:00",-1,15,15,15,0,-10,5,0,C',
        ];
    }

    /**
     * @param array<string, string> $files
     */
    private function import(array $files): ImportResult
    {
        $sources = [];
        foreach ($files as $name => $content) {
            $sources[] = new CsvSource($name, $content);
        }

        return self::service()->import($sources);
    }

    /**
     * An IBKR Activity Statement with the given Trades rows.
     *
     * @param list<string> $trades
     */
    private static function statement(array $trades): string
    {
        return "Statement,Header,Field Name,Field Value\n"
            ."Statement,Data,Title,Activity Statement\n"
            .self::TRADES_HEADER."\n"
            .implode("\n", $trades)."\n"
            .self::INSTRUMENTS;
    }

    private static function service(): CsvImportService
    {
        return new CsvImportService(
            new FormatDetector(),
            [
                new IbkrActivityStatementImporter(new FifoMatcher()),
                new DegiroTransactionsImporter(new FifoMatcher()),
                new DegiroAccountImporter(),
            ],
            new TaxRates(),
        );
    }
}
