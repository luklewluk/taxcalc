<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tax;

use App\Model\ClosedPosition;
use App\Money\Amount;
use App\Tax\StockTaxCalculator;
use App\Tests\Support\FixedExchange;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StockTaxCalculator::class)]
final class StockTaxCalculatorTest extends TestCase
{
    private StockTaxCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new StockTaxCalculator(FixedExchange::create());
    }

    public function testConvertsBothLegsAtTheirOwnTransactionDate(): void
    {
        $result = $this->calculator->calculate([
            self::position('AAPL', 'US', 'USD', '2024-05-04', '100.00', '2024-12-16', '150.00'),
        ]);

        self::assertCount(1, $result->positions);

        $position = $result->positions[0];
        // Fixed USD rate is 4.0000
        self::assertSame('400.00', (string) $position->cost->pln->value());
        self::assertSame('600.00', (string) $position->revenue->pln->value());
        self::assertSame('200.00', (string) $position->income->value());
        self::assertSame('2024-05-03', $position->cost->rateDate?->format('Y-m-d'));
        self::assertSame('2024-12-15', $position->revenue->rateDate?->format('Y-m-d'));
    }

    public function testTotalsAndNineteenPercentTax(): void
    {
        $result = $this->calculator->calculate([
            self::position('AAPL', 'US', 'USD', '2024-05-04', '100.00', '2024-12-16', '150.00'),
            self::position('INTC', 'US', 'USD', '2024-05-04', '50.00', '2024-12-16', '75.00'),
        ]);

        self::assertSame('900.00', (string) $result->totalRevenue->value());
        self::assertSame('600.00', (string) $result->totalCost->value());
        self::assertSame('300.00', (string) $result->income->value());
        self::assertSame('57.00', (string) $result->tax->value());
        self::assertSame('57', (string) $result->taxRoundedToZloty->value());
        self::assertFalse($result->isLoss());
    }

    public function testLossProducesZeroTaxNotNegativeTax(): void
    {
        $result = $this->calculator->calculate([
            self::position('OASIS', 'US', 'USD', '2024-05-04', '100.00', '2024-12-16', '20.00'),
        ]);

        self::assertSame('-320.00', (string) $result->income->value());
        self::assertSame('0.00', (string) $result->tax->value());
        self::assertTrue($result->isLoss());
        self::assertSame('320.00', (string) $result->loss->value());
    }

    public function testPlnPositionsNeedNoExchangeRate(): void
    {
        $result = $this->calculator->calculate([
            self::position('PKO', 'PL', 'PLN', '2024-02-01', '1000.00', '2024-08-01', '1200.00'),
        ]);

        self::assertSame('1', (string) $result->positions[0]->cost->rate);
        self::assertNull($result->positions[0]->cost->rateDate);
        self::assertSame('200.00', (string) $result->income->value());
        self::assertSame('38.00', (string) $result->tax->value());
    }

    public function testCountryBreakdownAggregatesRevenueCostAndIncome(): void
    {
        $result = $this->calculator->calculate([
            self::position('AAPL', 'US', 'USD', '2024-05-04', '100.00', '2024-12-16', '150.00'),
            self::position('INTC', 'US', 'USD', '2024-05-04', '50.00', '2024-12-16', '75.00'),
            self::position('CSPX', 'IE', 'USD', '2024-05-04', '10.00', '2024-12-16', '30.00'),
        ]);

        $byCountry = [];
        foreach ($result->countries as $country) {
            $byCountry[$country->countryCode] = $country;
        }

        $codes = array_keys($byCountry);
        sort($codes);
        self::assertSame(['IE', 'US'], $codes);

        self::assertSame('900.00', (string) $byCountry['US']->revenue->value());
        self::assertSame('600.00', (string) $byCountry['US']->cost->value());
        self::assertSame('300.00', (string) $byCountry['US']->income->value());

        self::assertSame('120.00', (string) $byCountry['IE']->revenue->value());
        self::assertSame('40.00', (string) $byCountry['IE']->cost->value());
        self::assertSame('80.00', (string) $byCountry['IE']->income->value());
    }

    public function testEmptyInputProducesZeroedResultNotAnError(): void
    {
        $result = $this->calculator->calculate([]);

        self::assertSame([], $result->positions);
        self::assertSame('0.00', (string) $result->totalRevenue->value());
        self::assertSame('0.00', (string) $result->tax->value());
        self::assertFalse($result->isLoss());
    }

    public function testTaxRoundingToFullZlotyIsHalfUp(): void
    {
        // income 50.00 PLN -> 19% = exactly 9.50 -> half-up to 10 zloty
        $result = $this->calculator->calculate([
            self::position('X', 'US', 'PLN', '2024-01-02', '1.00', '2024-06-02', '51.00'),
        ]);

        self::assertSame('9.50', (string) $result->tax->value());
        self::assertSame('10', (string) $result->taxRoundedToZloty->value());
    }

    public function testDeclaredFiguresAreRoundedOnceFromTheExactTax(): void
    {
        // income 2.63 PLN -> 19% = 0.4997. In grosze that shows as 0.50, but the
        // full-zloty figure must come from 0.4997 (-> 0), not from 0.50 (-> 1).
        $result = $this->calculator->calculate([
            self::position('X', 'US', 'PLN', '2024-01-02', '1.00', '2024-06-02', '3.63'),
        ]);

        self::assertSame('0.50', (string) $result->tax->value());
        self::assertSame('0', (string) $result->taxRoundedToZloty->value());
    }

    private static function position(
        string $name,
        string $country,
        string $currency,
        string $buyDate,
        string $buyAmount,
        string $sellDate,
        string $sellAmount,
    ): ClosedPosition {
        return new ClosedPosition(
            $name,
            $country,
            $currency,
            new DateTimeImmutable($buyDate),
            Amount::of($buyAmount, $currency),
            new DateTimeImmutable($sellDate),
            Amount::of($sellAmount, $currency),
            null,
            'test',
        );
    }
}
