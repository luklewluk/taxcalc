<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tax;

use App\Fifo\InstrumentKind;
use App\Fifo\PositionDirection;
use App\Model\ClosedPosition;
use App\Money\Amount;
use App\Money\Decimal;
use App\Tax\StockTaxCalculator;
use App\Tax\TaxYearFilter;
use App\Tests\Support\FixedExchange;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Options settle at close (art. 17 ust. 1b): the premium of a written option
 * is przychód converted at the rate before the *closing* day, while each cost
 * keeps the rate of the day it was paid (art. 11a). The test rates are flat
 * (USD 4.0), so the rate dates are what prove which day was used.
 */
#[CoversClass(StockTaxCalculator::class)]
#[CoversClass(TaxYearFilter::class)]
final class OptionTaxTest extends TestCase
{
    public function testAWrittenPutThatExpiresIsIncomeOnTheExpiryDate(): void
    {
        $position = self::option(PositionDirection::Short, '2026-01-16', '0', '0', '2025-12-15', '99', '1');

        $result = (new StockTaxCalculator(FixedExchange::create()))->calculate([$position]);
        $calculated = $result->positions[0];

        self::assertSame('400.00', (string) $calculated->revenue->pln->value());
        self::assertSame('2026-01-15', $calculated->revenue->rateDate?->format('Y-m-d'));
        self::assertSame('0.00', (string) $calculated->cost->pln->value());
        self::assertSame('4.00', (string) $calculated->disposalCost?->pln->value());
        self::assertSame('2025-12-14', $calculated->disposalCost?->rateDate?->format('Y-m-d'));
        self::assertSame('396.00', (string) $result->income->value());
        self::assertSame('75.24', (string) $result->tax->value());
        self::assertSame('75', (string) $result->taxRoundedToZloty->value());
    }

    public function testABoughtCallSoldLaterIsAnOrdinaryDisposal(): void
    {
        $position = self::option(PositionDirection::Long, '2025-11-03', '250.65', '0.65', '2026-02-10', '399.35', '0.65');

        $result = (new StockTaxCalculator(FixedExchange::create()))->calculate([$position]);

        self::assertSame('1600.00', (string) $result->totalRevenue->value());
        self::assertSame('1005.20', (string) $result->totalCost->value());
        self::assertSame('594.80', (string) $result->income->value());
        self::assertSame('113', (string) $result->taxRoundedToZloty->value());
    }

    public function testABoughtPutThatExpiresIsALossOnTheExpiryDate(): void
    {
        $position = self::option(PositionDirection::Long, '2025-11-03', '301.30', '1.30', '2026-01-16', '0', null, quantity: '2');

        $result = (new StockTaxCalculator(FixedExchange::create()))->calculate([$position]);

        self::assertSame('0.00', (string) $result->totalRevenue->value());
        self::assertSame('1205.20', (string) $result->loss->value());
        self::assertSame('0.00', (string) $result->tax->value());
        self::assertSame(2026, $position->taxYear());
    }

    public function testAnAssignedPutSettlesThePremiumApartFromTheShares(): void
    {
        $option = self::option(PositionDirection::Short, '2026-04-17', '0', '0', '2026-03-02', '198.95', '1.05');
        $shares = new ClosedPosition(
            'AAA',
            'US',
            'USD',
            new DateTimeImmutable('2026-04-17'),
            Amount::of('5000.00', 'USD'),
            new DateTimeImmutable('2026-06-01'),
            Amount::of('5599.00', 'USD'),
            Decimal::of('100'),
            'f.csv',
            sellCommission: Amount::of('1.00', 'USD'),
        );

        $result = (new StockTaxCalculator(FixedExchange::create()))->calculate([$option, $shares]);

        // The shares cost exactly the strike: the premium did not reduce it.
        self::assertSame('20000.00', (string) $result->positions[1]->cost->pln->value());
        self::assertSame('795.80', (string) $result->positions[0]->income->value());
        self::assertSame('23200.00', (string) $result->totalRevenue->value());
        self::assertSame('20008.20', (string) $result->totalCost->value());
        self::assertSame('606', (string) $result->taxRoundedToZloty->value());

        self::assertTrue($result->hasOptions());
        self::assertSame('800.00', (string) $result->optionRevenue()->value());
        self::assertSame('4.20', (string) $result->optionCost()->value());
    }

    public function testAStockOnlyResultHasNoOptions(): void
    {
        $result = (new StockTaxCalculator(FixedExchange::create()))->calculate([]);

        self::assertFalse($result->hasOptions());
        self::assertSame('0.00', (string) $result->optionRevenue()->value());
    }

    public function testAWrittenOptionBelongsToTheYearItClosed(): void
    {
        $position = self::option(PositionDirection::Short, '2026-01-16', '0', '0', '2025-12-15', '99', '1');
        $filter = new TaxYearFilter();

        self::assertSame([], $filter->positionsForYear([$position], 2025));
        self::assertCount(1, $filter->positionsForYear([$position], 2026));
        self::assertSame([2026], $filter->availableYears([$position], []));
    }

    private static function option(
        PositionDirection $direction,
        string $buyDate,
        string $buy,
        ?string $buyCommission,
        string $sellDate,
        string $sell,
        ?string $sellCommission,
        string $quantity = '1',
    ): ClosedPosition {
        return new ClosedPosition(
            'AAA 16JAN26 50 P',
            'US',
            'USD',
            new DateTimeImmutable($buyDate),
            Amount::of($buy, 'USD'),
            new DateTimeImmutable($sellDate),
            Amount::of($sell, 'USD'),
            Decimal::of($quantity),
            'f.csv',
            buyCommission: null === $buyCommission ? null : Amount::of($buyCommission, 'USD'),
            sellCommission: null === $sellCommission ? null : Amount::of($sellCommission, 'USD'),
            kind: InstrumentKind::Option,
            direction: $direction,
        );
    }
}
