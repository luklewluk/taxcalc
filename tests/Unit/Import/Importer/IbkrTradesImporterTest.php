<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import\Importer;

use App\Fifo\FifoMatcher;
use App\Import\CsvSource;
use App\Import\Importer\IbkrTradesImporter;
use App\Import\ImportResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IbkrTradesImporter::class)]
final class IbkrTradesImporterTest extends TestCase
{
    private const string HEADER = '"AssetClass","Symbol","TradeDate","Quantity","TradePrice","NetCash","TransactionID","CurrencyPrimary"';

    public function testMatchesBuysAndSellsIntoClosedPositions(): void
    {
        $result = $this->import([
            '"STK","CSPX","20240403","3","507.5782","-1523.98","1","EUR"',
            '"STK","CSPX","20250227","-3","560.00","1680.00","2","EUR"',
        ]);

        self::assertCount(1, $result->positions);

        $position = $result->positions[0];
        self::assertSame('CSPX', $position->name);
        self::assertSame('EUR', $position->currency);
        self::assertSame('2024-04-03', $position->buyDate->format('Y-m-d'));
        self::assertSame('1523.98', (string) $position->buyAmount->value());
        self::assertSame('2025-02-27', $position->sellDate->format('Y-m-d'));
        self::assertSame('1680.00', (string) $position->sellAmount->value());
        self::assertSame('3', (string) $position->quantity);
        self::assertSame('507.5782', (string) $position->buyUnitPrice?->value());
        self::assertSame('EUR', $position->buyUnitPrice?->currency());
        self::assertSame('560.00', (string) $position->sellUnitPrice?->value());
        self::assertSame('EUR', $position->sellUnitPrice?->currency());
    }

    public function testBuyFromAPreviousYearIsKeptForASaleInTheReportedYear(): void
    {
        $result = $this->import([
            '"STK","AAA","20210504","10","100","-1000.00","1","USD"',
            '"STK","AAA","20250120","-10","300","3000.00","2","USD"',
        ]);

        self::assertCount(1, $result->positions);
        self::assertSame(2021, (int) $result->positions[0]->buyDate->format('Y'));
        self::assertSame(2025, $result->positions[0]->taxYear());
    }

    public function testCountryIsLeftBlankWithAWarningBecauseTheFormatDoesNotCarryIt(): void
    {
        $result = $this->import([
            '"STK","AAA","20240101","1","10","-10.00","1","USD"',
            '"STK","AAA","20240601","-1","15","15.00","2","USD"',
        ]);

        self::assertSame('', $result->positions[0]->countryCode);
        self::assertNotEmpty($result->warnings());
        self::assertStringContainsString('kraj', mb_strtolower(implode(' ', $result->warnings())));
    }

    public function testNonStockRowsAreSkipped(): void
    {
        $result = $this->import([
            '"CASH","EUR.PLN","20240101","1000","4.3","-4300.00","1","EUR"',
            '"STK","AAA","20240101","1","10","-10.00","2","USD"',
            '"STK","AAA","20240601","-1","15","15.00","3","USD"',
        ]);

        self::assertCount(1, $result->positions);
    }

    public function testSkippedOptionsReachTheAttentionPanelButCurrencyConversionsDoNot(): void
    {
        $result = $this->import([
            '"CASH","EUR.PLN","20240101","1000","4.3","-4300.00","1","EUR"',
            '"OPT","AAA 240119C00100000","20240102","1","2.5","-250.65","4","USD"',
        ]);

        $review = array_values(array_filter(
            $result->messages,
            static fn (\App\Import\ImportMessage $m): bool => \App\Import\MessageLevel::Review === $m->level,
        ));

        self::assertCount(1, $review);
        self::assertStringContainsString('"OPT"', $review[0]->message);
        self::assertStringContainsString('Activity Statement', $review[0]->message);
        self::assertSame('transactions', $review[0]->targetTab);
    }

    public function testOpenPositionsAreNotReported(): void
    {
        $result = $this->import(['"STK","AAA","20240101","5","10","-50.00","1","USD"']);

        self::assertSame([], $result->positions);
        self::assertSame([], $result->dividends);
    }

    /**
     * Reported, never thrown - but as an error, because a sale with no purchase
     * behind it cannot be settled at all: its whole proceeds would read as gain.
     */
    public function testSellWithoutABuyIsAWarningNotAFailedImport(): void
    {
        $result = $this->import(['"STK","AAA","20240601","-5","15","75.00","1","USD"']);

        self::assertSame([], $result->positions);
        self::assertSame([], $result->errors());
        self::assertStringContainsString('AAA', implode(' ', $result->warnings()));
        self::assertStringContainsString('wcześniejszy rok', implode(' ', $result->warnings()));
    }

    public function testMalformedRowIsReportedWithItsLineNumberAndOthersStillImport(): void
    {
        $result = $this->import([
            '"STK","AAA","not-a-date","1","10","-10.00","1","USD"',
            '"STK","BBB","20240101","1","10","-10.00","2","USD"',
            '"STK","BBB","20240601","-1","15","15.00","3","USD"',
        ]);

        self::assertCount(1, $result->positions);
        self::assertCount(1, $result->errors());
        self::assertStringContainsString('2', $result->errors()[0]);
    }

    public function testInvalidCurrencyIsReportedAsAnError(): void
    {
        $result = $this->import(['"STK","AAA","20240101","1","10","-10.00","1","dollars"']);

        self::assertSame([], $result->positions);
        self::assertCount(1, $result->errors());
    }

    public function testBlankTradePriceRemainsMissingAuditData(): void
    {
        $result = $this->import([
            '"STK","AAA","20240101","1","","-10.00","1","USD"',
            '"STK","AAA","20240601","-1","15","15.00","2","USD"',
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->positions);
        self::assertNull($result->positions[0]->buyUnitPrice);
        self::assertSame('15', (string) $result->positions[0]->sellUnitPrice?->value());
    }

    public function testNonPositiveTradePriceIsReportedAsAnError(): void
    {
        $result = $this->import(['"STK","AAA","20240101","1","0","-10.00","1","USD"']);

        self::assertSame([], $result->positions);
        self::assertCount(1, $result->errors());
        self::assertStringContainsString('TradePrice', $result->errors()[0]);
    }

    public function testMissingRequiredColumnIsAFileLevelError(): void
    {
        $source = new CsvSource('trades.csv', "AssetClass,Symbol\nSTK,AAA\n");
        $result = (new IbkrTradesImporter(new FifoMatcher()))->import($source);

        self::assertSame([], $result->positions);
        self::assertNotEmpty($result->errors());
    }

    public function testCommissionIsIncludedBecauseNetCashIsUsed(): void
    {
        // TradePrice * Quantity = 1522.73 but NetCash is 1523.98 (incl. commission)
        $result = $this->import([
            '"STK","CSPX","20240403","3","507.5782","-1523.98","1","EUR"',
            '"STK","CSPX","20250227","-3","560.00","1678.75","2","EUR"',
        ]);

        self::assertSame('1523.98', (string) $result->positions[0]->buyAmount->value());
        self::assertSame('1678.75', (string) $result->positions[0]->sellAmount->value());
    }

    /**
     * @param list<string> $rows
     */
    private function import(array $rows): ImportResult
    {
        $content = self::HEADER."\n".implode("\n", $rows)."\n";

        return (new IbkrTradesImporter(new FifoMatcher()))->import(new CsvSource('trades.csv', $content));
    }
}
