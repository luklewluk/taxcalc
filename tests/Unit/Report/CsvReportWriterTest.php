<?php

declare(strict_types=1);

namespace App\Tests\Unit\Report;

use App\Model\ClosedPosition;
use App\Model\Dividend;
use App\Money\Amount;
use App\Report\CsvReportWriter;
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
        $exchange = FixedExchange::create();
        $builder = new TaxReportBuilder(
            new TaxYearFilter(),
            new StockTaxCalculator($exchange),
            new DividendTaxCalculator($exchange, new TaxRates()),
            $exchange,
        );

        return (new CsvReportWriter())->write($builder->build($positions, $dividends, 2024));
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

    private static function position(
        string $name,
        string $country,
        string $buyDate,
        string $buyAmount,
        string $sellDate,
        string $sellAmount,
        ?string $sellCommission = null,
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
