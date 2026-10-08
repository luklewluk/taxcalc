<?php

declare(strict_types=1);

namespace App\Tests\Unit\Settlement;

use App\Fifo\InstrumentKind;
use App\Settlement\SettlementCycle;
use App\Settlement\SettlementDateResolver;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SettlementDateResolver::class)]
#[CoversClass(SettlementCycle::class)]
final class SettlementDateResolverTest extends TestCase
{
    public function testTheTradeDateCycleMovesNothing(): void
    {
        self::assertNull((new SettlementDateResolver())->settle(new DateTimeImmutable('2025-03-07'), 'US', InstrumentKind::Stock, SettlementCycle::TradeDate));
    }

    /** @return iterable<string, array{string, string, InstrumentKind, string}> */
    public static function marketCycles(): iterable
    {
        yield 'US T+2 before the switch, over Memorial Day' => ['2024-05-24', 'US', InstrumentKind::Stock, '2024-05-29'];
        yield 'US T+1 from 28 May 2024' => ['2024-05-28', 'US', InstrumentKind::Stock, '2024-05-29'];
        yield 'US T+1 over Thanksgiving' => ['2024-11-27', 'US', InstrumentKind::Stock, '2024-11-29'];
        yield 'US T+1 over New Year, into the next year' => ['2024-12-31', 'US', InstrumentKind::Stock, '2025-01-02'];
        yield 'US T+3 before September 2017, over Labor Day' => ['2017-09-01', 'US', InstrumentKind::Stock, '2017-09-07'];
        yield 'Canada T+1 from 27 May 2024' => ['2024-05-27', 'CA', InstrumentKind::Stock, '2024-05-28'];
        yield 'Germany T+2 over Christmas and Xetra closures' => ['2024-12-23', 'DE', InstrumentKind::Stock, '2024-12-30'];
        yield 'Europe T+1 from 11 October 2027' => ['2027-10-11', 'DE', InstrumentKind::Stock, '2027-10-12'];
        yield 'options settle T+1' => ['2025-03-07', 'US', InstrumentKind::Option, '2025-03-10'];
        yield 'a market without a calendar: T+2 over a weekend' => ['2025-01-03', 'JP', InstrumentKind::Stock, '2025-01-07'];
        yield 'no market at all: weekends only' => ['2025-01-03', '', InstrumentKind::Stock, '2025-01-07'];
    }

    #[DataProvider('marketCycles')]
    public function testTheMarketCycleCountsTheMarketsBusinessDays(string $trade, string $market, InstrumentKind $kind, string $settled): void
    {
        $date = (new SettlementDateResolver())->settle(new DateTimeImmutable($trade.' 15:30:00'), $market, $kind, SettlementCycle::Market);

        self::assertSame($settled, $date?->format('Y-m-d'));
        self::assertSame('00:00:00', $date?->format('H:i:s'));
    }

    public function testThePolishCycleIsTwoPolishBusinessDaysForStocksAndOneForOptions(): void
    {
        $resolver = new SettlementDateResolver();

        // Good Friday is a business day in Poland, Easter Monday is not.
        self::assertSame('2025-04-22', $resolver->settle(new DateTimeImmutable('2025-04-17'), 'US', InstrumentKind::Stock, SettlementCycle::PolishD2)?->format('Y-m-d'));
        self::assertSame('2025-04-18', $resolver->settle(new DateTimeImmutable('2025-04-17'), 'US', InstrumentKind::Option, SettlementCycle::PolishD2)?->format('Y-m-d'));
    }
}
