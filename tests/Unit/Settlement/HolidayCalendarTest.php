<?php

declare(strict_types=1);

namespace App\Tests\Unit\Settlement;

use App\Settlement\HolidayCalendar;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HolidayCalendar::class)]
final class HolidayCalendarTest extends TestCase
{
    public function testPolishStatutoryHolidaysFollowEasterAndGainChristmasEveIn2025(): void
    {
        self::assertSame(
            ['2024-01-01', '2024-01-06', '2024-04-01', '2024-05-01', '2024-05-03', '2024-05-30', '2024-08-15', '2024-11-01', '2024-11-11', '2024-12-25', '2024-12-26'],
            HolidayCalendar::holidays(HolidayCalendar::POLISH_STATUTORY, 2024),
        );
        self::assertSame(
            ['2025-01-01', '2025-01-06', '2025-04-21', '2025-05-01', '2025-05-03', '2025-06-19', '2025-08-15', '2025-11-01', '2025-11-11', '2025-12-24', '2025-12-25', '2025-12-26'],
            HolidayCalendar::holidays(HolidayCalendar::POLISH_STATUTORY, 2025),
        );
    }

    public function testTheWarsawExchangeAlsoClosesOnGoodFridayAndNewYearsEve(): void
    {
        self::assertFalse(HolidayCalendar::isBusinessDay(new DateTimeImmutable('2025-04-18'), 'PL'));
        self::assertTrue(HolidayCalendar::isBusinessDay(new DateTimeImmutable('2025-04-18'), HolidayCalendar::POLISH_STATUTORY));
        self::assertFalse(HolidayCalendar::isBusinessDay(new DateTimeImmutable('2024-12-31'), 'PL'));
    }

    public function testUnitedStatesUnitesExchangeAndSettlementClosures(): void
    {
        self::assertSame(
            ['2024-01-01', '2024-01-15', '2024-02-19', '2024-03-29', '2024-05-27', '2024-06-19', '2024-07-04', '2024-09-02', '2024-10-14', '2024-11-11', '2024-11-28', '2024-12-25'],
            HolidayCalendar::holidays('US', 2024),
        );
    }

    /** @return iterable<string, array{string, bool}> */
    public static function usObservedDays(): iterable
    {
        yield 'Independence Day on a Saturday closes the Friday' => ['2026-07-03', false];
        yield 'Juneteenth on a Sunday closes the Monday' => ['2022-06-20', false];
        yield 'New Year on a Saturday leaves 31 December open' => ['2021-12-31', true];
        yield 'Christmas on a Sunday closes the Monday' => ['2022-12-26', false];
        yield 'Juneteenth did not exist yet in 2021' => ['2021-06-18', true];
    }

    #[DataProvider('usObservedDays')]
    public function testUnitedStatesObservesWeekendHolidays(string $day, bool $open): void
    {
        self::assertSame($open, HolidayCalendar::isBusinessDay(new DateTimeImmutable($day), 'US'));
    }

    public function testBritainMovesChristmasAndBoxingDayOffTheWeekend(): void
    {
        $holidays = HolidayCalendar::holidays('GB', 2022);

        self::assertContains('2022-12-26', $holidays);
        self::assertContains('2022-12-27', $holidays);
        self::assertContains('2022-04-15', $holidays, 'Good Friday');
        self::assertContains('2022-08-29', $holidays, 'Summer bank holiday');
    }

    public function testEuroMarketsFollowTargetPlusTheirOwnFixedClosures(): void
    {
        self::assertSame(['2025-01-01', '2025-04-18', '2025-04-21', '2025-05-01', '2025-12-25', '2025-12-26'], HolidayCalendar::holidays('FR', 2025));
        self::assertFalse(HolidayCalendar::isBusinessDay(new DateTimeImmutable('2025-12-24'), 'DE'));
        self::assertTrue(HolidayCalendar::isBusinessDay(new DateTimeImmutable('2025-12-24'), 'NL'));
        self::assertFalse(HolidayCalendar::isBusinessDay(new DateTimeImmutable('2024-12-06'), 'FI'));
    }

    public function testSwitzerlandAndTheNordicsKeepTheirOwnDays(): void
    {
        self::assertFalse(HolidayCalendar::isBusinessDay(new DateTimeImmutable('2025-01-02'), 'CH'));
        self::assertFalse(HolidayCalendar::isBusinessDay(new DateTimeImmutable('2025-08-01'), 'CH'));
        self::assertFalse(HolidayCalendar::isBusinessDay(new DateTimeImmutable('2025-06-06'), 'SE'));
        self::assertFalse(HolidayCalendar::isBusinessDay(new DateTimeImmutable('2025-06-20'), 'SE'), 'Midsummer Eve');
        self::assertFalse(HolidayCalendar::isBusinessDay(new DateTimeImmutable('2024-05-17'), 'NO'));
        self::assertFalse(HolidayCalendar::isBusinessDay(new DateTimeImmutable('2023-05-05'), 'DK'), 'Great Prayer Day, last held in 2023');
        self::assertTrue(HolidayCalendar::isBusinessDay(new DateTimeImmutable('2024-04-26'), 'DK'), 'abolished from 2024');
    }

    public function testCanadaAndMexico(): void
    {
        self::assertSame(
            ['2025-01-01', '2025-02-17', '2025-04-18', '2025-05-19', '2025-07-01', '2025-08-04', '2025-09-01', '2025-10-13', '2025-11-11', '2025-12-25', '2025-12-26'],
            HolidayCalendar::holidays('CA', 2025),
        );
        self::assertFalse(HolidayCalendar::isBusinessDay(new DateTimeImmutable('2025-04-17'), 'MX'), 'Holy Thursday');
        self::assertFalse(HolidayCalendar::isBusinessDay(new DateTimeImmutable('2025-12-12'), 'MX'));
    }

    public function testAMarketWithoutACalendarClosesOnlyAtWeekends(): void
    {
        self::assertFalse(HolidayCalendar::covers('JP'));
        self::assertTrue(HolidayCalendar::covers('US'));
        self::assertSame([], HolidayCalendar::holidays('JP', 2025));
        self::assertTrue(HolidayCalendar::isBusinessDay(new DateTimeImmutable('2025-01-01'), 'JP'));
        self::assertFalse(HolidayCalendar::isBusinessDay(new DateTimeImmutable('2025-01-04'), 'JP'));
        self::assertFalse(HolidayCalendar::isBusinessDay(new DateTimeImmutable('2025-01-05'), ''));
    }
}
