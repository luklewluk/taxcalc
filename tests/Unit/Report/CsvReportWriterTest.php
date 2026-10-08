<?php

declare(strict_types=1);

namespace App\Tests\Unit\Report;

use App\Fifo\LotAllocation;
use App\Fifo\LotAssignments;
use App\Fifo\LotMethod;
use App\Fifo\Trade;
use App\Model\ClosedPosition;
use App\Model\Dividend;
use App\Money\Amount;
use App\Money\Decimal;
use App\Report\CsvReportWriter;
use App\Report\TaxReport;
use App\Settlement\SettlementCycle;
use App\Report\TaxReportBuilder;
use App\Tax\DividendTaxCalculator;
use App\Tax\StockTaxCalculator;
use App\Tax\TaxRates;
use App\Tax\TaxYearFilter;
use App\Tests\Support\FixedExchange;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CsvReportWriter::class)]
final class CsvReportWriterTest extends TestCase
{
    public function testWritesDetailedPositionAndDividendRows(): void
    {
        $csv = $this->write(
            [self::position('AAPL', 'US', '2024-05-04', '100.00', '2024-12-16', '150.00')],
            [self::dividend('AAPL', 'US', '2024-06-10', '100.00', '15.00')],
        );

        self::assertStringContainsString('AAPL', $csv);
        // Original currency amount, NBP rate and the PLN result are all present.
        self::assertStringContainsString('100.00', $csv);
        self::assertStringContainsString('4.0000', $csv);
        self::assertStringContainsString('400.00', $csv);
        self::assertStringContainsString('600.00', $csv);
        // Summary figures.
        self::assertStringContainsString('200.00', $csv);
    }

    public function testIncludesTheNbpRateDateForEachLeg(): void
    {
        $csv = $this->write(
            [self::position('AAPL', 'US', '2024-05-04', '100.00', '2024-12-16', '150.00')],
            [],
        );

        self::assertStringContainsString('2024-05-03', $csv);
        self::assertStringContainsString('2024-12-15', $csv);
    }

    public function testFormulaInjectionIsNeutralisedInTextFields(): void
    {
        $csv = $this->write(
            [self::position('=HYPERLINK("http://evil","x")', 'US', '2024-05-04', '100.00', '2024-12-16', '150.00')],
            [self::dividend('+CMD|\' /C calc\'!A0', 'US', '2024-06-10', '10.00', '1.50')],
        );

        self::assertStringNotContainsString(',=HYPERLINK', $csv);
        self::assertStringNotContainsString(',+CMD', $csv);
        self::assertStringContainsString("'=HYPERLINK", $csv);
        self::assertStringContainsString("'+CMD", $csv);
    }

    public function testNegativeNumbersAreNotMangledByTheInjectionGuard(): void
    {
        $csv = $this->write(
            [self::position('AAA', 'US', '2024-05-04', '100.00', '2024-12-16', '20.00')],
            [],
        );

        self::assertStringContainsString('-320.00', $csv);
        self::assertStringNotContainsString("'-320.00", $csv);
    }

    public function testStartsWithAUtf8ByteOrderMarkSoSpreadsheetsShowPolishCharacters(): void
    {
        $csv = $this->write([], []);

        self::assertStringStartsWith("\u{FEFF}", $csv);
    }

    public function testDisclaimerIsPartOfTheExport(): void
    {
        $csv = $this->write([], []);

        self::assertStringContainsString('nie stanowi porady podatkowej', $csv);
    }

    public function testTaxYearIsStatedInsteadOfBrittlePitFieldNumbers(): void
    {
        $csv = $this->write([], []);

        self::assertStringContainsString('2024', $csv);
    }

    /**
     * @param list<ClosedPosition> $positions
     * @param list<Dividend>       $dividends
     */
    private function write(array $positions, array $dividends): string
    {
        return (new CsvReportWriter())->write($this->report($positions, $dividends));
    }

    /**
     * @param list<ClosedPosition> $positions
     * @param list<Dividend>       $dividends
     */
    private function report(array $positions, array $dividends): TaxReport
    {
        $exchange = FixedExchange::create();
        $builder = new TaxReportBuilder(
            new TaxYearFilter(),
            new StockTaxCalculator($exchange),
            new DividendTaxCalculator($exchange, new TaxRates()),
            $exchange,
        );

        return $builder->build($positions, $dividends, 2024);
    }

    public function testThePositionRowSeparatesTheGrossAmountFromTheSettledCash(): void
    {
        $csv = $this->write([
            self::position('AAA', 'US', '2024-05-04', '100.00', '2024-12-16', '150.00', sellCommission: '1.00'),
        ], []);

        self::assertStringContainsString('Kwota nalezna (brutto)', $csv);
        self::assertStringContainsString('Koszt zbycia (PLN)', $csv);
        // Settled cash 150.00 next to the gross 151.00; rate 4.0 gives a
        // przychód of 604.00 and a disposal cost of 4.00 on top of the 400.00
        // acquisition cost.
        self::assertStringContainsString('150.00,151.00', $csv);
        self::assertStringContainsString('604.00,4.00', $csv);
        self::assertStringContainsString('"Koszty zbycia",4.00', $csv);
    }

    public function testAWrittenOptionSaysWhatItIsAndWhichRatesItUsed(): void
    {
        $option = new ClosedPosition(
            'AAA 19APR24 50 P',
            'US',
            'USD',
            new DateTimeImmutable('2024-04-19'),
            Amount::of('0', 'USD'),
            new DateTimeImmutable('2024-03-04'),
            Amount::of('99', 'USD'),
            \App\Money\Decimal::of('1'),
            'as.csv',
            sellCommission: Amount::of('1', 'USD'),
            kind: \App\Fifo\InstrumentKind::Option,
            direction: \App\Fifo\PositionDirection::Short,
        );

        $csv = $this->write([$option], []);

        self::assertStringContainsString('AKCJE, ETF I OPCJE (PIT-38 czesc C)', $csv);
        self::assertStringContainsString('Rodzaj instrumentu', $csv);
        // Closed on the buy; the writing fee keeps the writing day's rate.
        self::assertStringContainsString(',Opcja,krotka,2024-04-19,4.0000,2024-03-03', $csv);
    }

    public function testAStockOnlyReportKeepsItsSectionTitle(): void
    {
        $csv = $this->write([self::position('AAA', 'US', '2024-05-04', '100.00', '2024-12-16', '150.00')], []);

        self::assertStringContainsString('AKCJE I ETF (PIT-38 czesc C)', $csv);
        self::assertStringContainsString(',Akcje,dluga,2024-12-16,,', $csv);
    }

    public function testEveryPositionNamesHowItsLotWasChosen(): void
    {
        $fifo = self::position('AAA', 'US', '2024-05-04', '100.00', '2024-12-16', '150.00');
        $named = self::position('BBB', 'US', '2024-05-04', '100.00', '2024-12-16', '150.00', lotMethod: LotMethod::Specific);

        $csv = $this->write([$fifo, $named], []);

        self::assertStringContainsString(',"Data kursu kosztu zbycia","Metoda doboru partii"', $csv);
        self::assertStringContainsString(',Akcje,dluga,2024-12-16,,,FIFO', $csv);
        self::assertStringContainsString(',Akcje,dluga,2024-12-16,,,"wskazanie partii"', $csv);
        self::assertStringContainsString('0112-KDIL2-1.4011.929.2025.1.TR', $csv);
    }

    public function testTheReportStatesItsSettlementCycleOnceAndEachLegsSettlement(): void
    {
        $position = self::position('AAA', 'US', '2024-05-03', '100.00', '2024-12-16', '150.00')
            ->withSettlement(new DateTimeImmutable('2024-05-07'), new DateTimeImmutable('2024-12-17'));

        $csv = (new CsvReportWriter())->write($this->report([$position], []), cycle: SettlementCycle::Market);

        self::assertStringContainsString('"Cykl rozliczenia","Dzień rozliczenia wg giełdy (D+1 / D+2)"', $csv);
        self::assertStringContainsString('"Metoda doboru partii","Data rozliczenia zakupu","Data rozliczenia sprzedazy"', $csv);
        self::assertStringContainsString(',FIFO,2024-05-07,2024-12-17', $csv);
    }

    public function testAFifoOnlyReportCarriesNoLotNote(): void
    {
        $csv = $this->write([self::position('AAA', 'US', '2024-05-04', '100.00', '2024-12-16', '150.00')], []);

        self::assertStringNotContainsString('0112-KDIL2', $csv);
        self::assertStringNotContainsString('WSKAZANE PARTIE', $csv);
    }

    public function testTheChosenLotsAreListedWithTheirTrades(): void
    {
        $buy = self::trade('b-1', '2024-05-04', '2');
        $sell = self::trade('s-1', '2024-12-16', '-2');
        $assignments = (new LotAssignments())->with('s-1', [new LotAllocation('b-1', Decimal::of('2'))]);

        $csv = (new CsvReportWriter())->write(
            $this->report([], []),
            [$buy, $sell],
            assignments: $assignments,
        );

        self::assertStringContainsString('"AKTUALNY STAN - WSKAZANE PARTIE"', $csv);
        self::assertStringContainsString('"ID sprzedazy","Data sprzedazy","ID partii","Data zakupu partii",Liczba', $csv);
        self::assertStringContainsString('s-1,2024-12-16,b-1,2024-05-04,2', $csv);
    }

    private static function trade(string $id, string $date, string $quantity): Trade
    {
        return new Trade(
            'AAA',
            new DateTimeImmutable($date),
            Decimal::of($quantity),
            Amount::of('-' === $quantity[0] ? '150.00' : '-100.00', 'USD'),
            source: 'test',
            stableId: $id,
        );
    }

    private static function position(
        string $name,
        string $country,
        string $buyDate,
        string $buyAmount,
        string $sellDate,
        string $sellAmount,
        ?string $sellCommission = null,
        LotMethod $lotMethod = LotMethod::Fifo,
    ): ClosedPosition {
        return new ClosedPosition(
            $name,
            $country,
            'USD',
            new DateTimeImmutable($buyDate),
            Amount::of($buyAmount, 'USD'),
            new DateTimeImmutable($sellDate),
            Amount::of($sellAmount, 'USD'),
            null,
            'test',
            sellCommission: null === $sellCommission ? null : Amount::of($sellCommission, 'USD'),
            lotMethod: $lotMethod,
        );
    }

    private static function dividend(
        string $name,
        string $country,
        string $date,
        string $gross,
        string $withheld,
    ): Dividend {
        return new Dividend(
            $name,
            $country,
            'USD',
            new DateTimeImmutable($date),
            Amount::of($gross, 'USD'),
            Amount::of($withheld, 'USD'),
            'test',
        );
    }
}
