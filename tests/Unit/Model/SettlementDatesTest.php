<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Fifo\InstrumentKind;
use App\Fifo\PositionDirection;
use App\Model\ClosedPosition;
use App\Money\Amount;
use App\Money\Decimal;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Under a settlement cycle each leg is converted at the rate before the day it
 * settles, and the closing leg's settlement decides the tax year. The trade
 * dates stay what they are - they order the queue and are what the reader
 * recognises - and so does the position's identity.
 */
#[CoversClass(ClosedPosition::class)]
final class SettlementDatesTest extends TestCase
{
    public function testWithoutSettlementDatesEverythingFollowsTheTrades(): void
    {
        $position = self::stock();

        self::assertNull($position->buySettlement);
        self::assertSame('2025-03-03', $position->buyRateDate()->format('Y-m-d'));
        self::assertSame('2025-12-31', $position->sellRateDate()->format('Y-m-d'));
        self::assertSame(2025, $position->taxYear());
    }

    public function testASaleSettledInJanuaryBelongsToTheNextYear(): void
    {
        $position = self::stock()->withSettlement(new DateTimeImmutable('2025-03-04'), new DateTimeImmutable('2026-01-02'));

        self::assertSame('2025-03-03', $position->buyDate->format('Y-m-d'));
        self::assertSame('2025-12-31', $position->closeDate()->format('Y-m-d'));
        self::assertSame('2025-03-04', $position->buyRateDate()->format('Y-m-d'));
        self::assertSame('2026-01-02', $position->sellRateDate()->format('Y-m-d'));
        self::assertSame('2026-01-02', $position->revenueDate()->format('Y-m-d'));
        self::assertSame(2026, $position->taxYear());
        self::assertSame(['2025-03-04', '2026-01-02'], self::dates($position->conversionDates()));
        self::assertSame(self::stock()->fingerprint(), $position->fingerprint());
    }

    public function testAWrittenOptionTakesItsRevenueDayFromTheClosingBuy(): void
    {
        $position = new ClosedPosition(
            'AAA 16JAN26 50 P',
            'US',
            'USD',
            new DateTimeImmutable('2025-12-30'),
            Amount::of('40', 'USD'),
            new DateTimeImmutable('2025-11-03'),
            Amount::of('99', 'USD'),
            Decimal::of('1'),
            'f.csv',
            kind: InstrumentKind::Option,
            direction: PositionDirection::Short,
        );
        $settled = $position->withSettlement(new DateTimeImmutable('2025-12-31'), new DateTimeImmutable('2025-11-04'));

        self::assertSame('2025-12-31', $settled->revenueDate()->format('Y-m-d'));
        self::assertSame(['2025-12-31', '2025-11-04'], self::dates($settled->conversionDates()));
        self::assertSame(2025, $settled->taxYear());
    }

    private static function stock(): ClosedPosition
    {
        return new ClosedPosition(
            'ALFA CORP',
            'US',
            'USD',
            new DateTimeImmutable('2025-03-03'),
            Amount::of('1001.00', 'USD'),
            new DateTimeImmutable('2025-12-31'),
            Amount::of('1198.50', 'USD'),
            Decimal::of('10'),
            'f.csv',
        );
    }

    /**
     * @param list<DateTimeImmutable> $dates
     *
     * @return list<string>
     */
    private static function dates(array $dates): array
    {
        return array_map(static fn (DateTimeImmutable $date): string => $date->format('Y-m-d'), $dates);
    }
}
