<?php

declare(strict_types=1);

namespace App\Settlement;

use DateTimeImmutable;

/**
 * Days a market does not settle trades, by rule rather than by table: fixed
 * dates, days counted from Easter, the n-th weekday of a month, and the
 * weekend substitutes the US, Britain and Canada observe. Years need no
 * maintenance; one-off closures (a day of mourning, a coronation) are not here,
 * and a market without rules closes only at weekends - both said in the
 * methodology.
 *
 * A market is an ISO country code. Where the exchange and the settlement
 * system close on different days, a day either closes counts: no trade
 * settles on it.
 */
final class HolidayCalendar
{
    /** Polish statutory holidays alone - the days NBP publishes no table. */
    public const string POLISH_STATUTORY = 'PL-STATUTORY';

    /** Euro markets settle on the TARGET calendar. */
    private const array EURO = ['AT', 'BE', 'CY', 'DE', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PT', 'SI', 'SK'];

    private const array OWN = [self::POLISH_STATUTORY, 'PL', 'US', 'CA', 'MX', 'GB', 'CH', 'DK', 'SE', 'NO'];

    /** @var array<string, array<string, true>> "market|year" => set of Y-m-d */
    private static array $cache = [];

    public static function covers(string $market): bool
    {
        return in_array($market, self::OWN, true) || in_array($market, self::EURO, true);
    }

    public static function isBusinessDay(DateTimeImmutable $day, string $market): bool
    {
        if ((int) $day->format('N') >= 6) {
            return false;
        }

        $year = (int) $day->format('Y');
        $key = $market.'|'.$year;
        self::$cache[$key] ??= array_fill_keys(self::holidays($market, $year), true);

        return !isset(self::$cache[$key][$day->format('Y-m-d')]);
    }

    /**
     * Every holiday of the year, as Y-m-d, sorted. A holiday falling on a
     * weekend is listed as it falls; its weekday substitute, where the market
     * observes one, is listed too.
     *
     * @return list<string>
     */
    public static function holidays(string $market, int $year): array
    {
        $days = match (true) {
            self::POLISH_STATUTORY === $market => self::polish($year),
            // The Warsaw exchange also closes on Good Friday and New Year's Eve.
            'PL' === $market => [...self::polish($year), self::easter($year, -2), self::day($year, 12, 24), self::day($year, 12, 31)],
            'US' === $market => self::unitedStates($year),
            'CA' === $market => self::canada($year),
            'MX' === $market => self::mexico($year),
            'GB' === $market => self::britain($year),
            'CH' === $market => self::switzerland($year),
            'DK' === $market => self::denmark($year),
            'SE' === $market => self::sweden($year),
            'NO' === $market => self::norway($year),
            in_array($market, self::EURO, true) => [...self::target($year), ...self::euroLocal($market, $year)],
            default => [],
        };

        $days = array_values(array_unique($days));
        sort($days);

        return $days;
    }

    /** @return list<string> */
    private static function polish(int $year): array
    {
        $days = [
            self::day($year, 1, 1), self::day($year, 1, 6), self::easter($year, 1), self::day($year, 5, 1),
            self::day($year, 5, 3), self::easter($year, 60), self::day($year, 8, 15), self::day($year, 11, 1),
            self::day($year, 11, 11), self::day($year, 12, 25), self::day($year, 12, 26),
        ];
        // Christmas Eve is a public holiday from 2025.
        if ($year >= 2025) {
            $days[] = self::day($year, 12, 24);
        }

        return $days;
    }

    /**
     * NYSE closures and Federal Reserve holidays together - DTC does not
     * settle on a Fed holiday even when the exchange trades (Columbus Day,
     * Veterans Day).
     *
     * @return list<string>
     */
    private static function unitedStates(int $year): array
    {
        $days = [
            self::nthWeekday($year, 1, 1, 3), // Martin Luther King Jr. Day
            self::nthWeekday($year, 2, 1, 3), // Washington's Birthday
            self::easter($year, -2),
            self::lastWeekday($year, 5, 1), // Memorial Day
            self::nthWeekday($year, 9, 1, 1), // Labor Day
            self::nthWeekday($year, 10, 1, 2), // Columbus Day
            self::nthWeekday($year, 11, 4, 4), // Thanksgiving
        ];
        // New Year: a Sunday moves to Monday; a Saturday is not made up on the Friday before.
        $days = [...$days, ...self::sundayToMonday($year, 1, 1)];
        // Veterans Day: the Fed makes up a Sunday only.
        $days = [...$days, ...self::sundayToMonday($year, 11, 11)];
        foreach ([[7, 4], [12, 25]] as [$month, $dayOfMonth]) {
            $days = [...$days, ...self::nearestWeekday($year, $month, $dayOfMonth)];
        }
        if ($year >= 2022) {
            $days = [...$days, ...self::nearestWeekday($year, 6, 19)]; // Juneteenth
        }

        return $days;
    }

    /** @return list<string> */
    private static function canada(int $year): array
    {
        $victoria = new DateTimeImmutable(sprintf('%d-05-24', $year));
        $victoria = $victoria->modify('-'.(((int) $victoria->format('N')) - 1).' days');

        return [
            ...self::weekendToMonday($year, 1, 1),
            self::nthWeekday($year, 2, 1, 3), // Family Day
            self::easter($year, -2),
            $victoria->format('Y-m-d'), // the Monday on or before 24 May
            ...self::weekendToMonday($year, 7, 1), // Canada Day
            self::nthWeekday($year, 8, 1, 1), // Civic Holiday
            self::nthWeekday($year, 9, 1, 1), // Labour Day
            self::nthWeekday($year, 10, 1, 2), // Thanksgiving
            ...self::weekendToMonday($year, 11, 11), // Remembrance Day: CDS does not settle
            ...self::christmasAndBoxingDay($year),
        ];
    }

    /** @return list<string> */
    private static function mexico(int $year): array
    {
        return [
            self::day($year, 1, 1), self::nthWeekday($year, 2, 1, 1), self::nthWeekday($year, 3, 1, 3),
            self::easter($year, -3), self::easter($year, -2), self::day($year, 5, 1), self::day($year, 9, 16),
            self::nthWeekday($year, 11, 1, 3), self::day($year, 12, 12), self::day($year, 12, 25),
        ];
    }

    /** @return list<string> */
    private static function britain(int $year): array
    {
        return [
            ...self::weekendToMonday($year, 1, 1),
            self::easter($year, -2), self::easter($year, 1),
            self::nthWeekday($year, 5, 1, 1), // Early May bank holiday
            self::lastWeekday($year, 5, 1), // Spring bank holiday
            self::lastWeekday($year, 8, 1), // Summer bank holiday
            ...self::christmasAndBoxingDay($year),
        ];
    }

    /** @return list<string> */
    private static function switzerland(int $year): array
    {
        return [
            self::day($year, 1, 1), self::day($year, 1, 2), self::easter($year, -2), self::easter($year, 1),
            self::day($year, 5, 1), self::easter($year, 39), self::easter($year, 50), self::day($year, 8, 1),
            self::day($year, 12, 24), self::day($year, 12, 25), self::day($year, 12, 26), self::day($year, 12, 31),
        ];
    }

    /** @return list<string> */
    private static function denmark(int $year): array
    {
        $days = [
            self::day($year, 1, 1), self::easter($year, -3), self::easter($year, -2), self::easter($year, 1),
            self::easter($year, 39), self::easter($year, 40), self::easter($year, 50), self::day($year, 6, 5),
            self::day($year, 12, 24), self::day($year, 12, 25), self::day($year, 12, 26), self::day($year, 12, 31),
        ];
        // Great Prayer Day was abolished from 2024.
        if ($year <= 2023) {
            $days[] = self::easter($year, 26);
        }

        return $days;
    }

    /** @return list<string> */
    private static function sweden(int $year): array
    {
        return [
            self::day($year, 1, 1), self::day($year, 1, 6), self::easter($year, -2), self::easter($year, 1),
            self::day($year, 5, 1), self::easter($year, 39), self::day($year, 6, 6), self::midsummerEve($year),
            self::day($year, 12, 24), self::day($year, 12, 25), self::day($year, 12, 26), self::day($year, 12, 31),
        ];
    }

    /** @return list<string> */
    private static function norway(int $year): array
    {
        return [
            self::day($year, 1, 1), self::easter($year, -3), self::easter($year, -2), self::easter($year, 1),
            self::day($year, 5, 1), self::day($year, 5, 17), self::easter($year, 39), self::easter($year, 50),
            self::day($year, 12, 24), self::day($year, 12, 25), self::day($year, 12, 26), self::day($year, 12, 31),
        ];
    }

    /** @return list<string> */
    private static function target(int $year): array
    {
        return [
            self::day($year, 1, 1), self::easter($year, -2), self::easter($year, 1),
            self::day($year, 5, 1), self::day($year, 12, 25), self::day($year, 12, 26),
        ];
    }

    /**
     * Fixed closures of an exchange on top of TARGET.
     *
     * @return list<string>
     */
    private static function euroLocal(string $market, int $year): array
    {
        return match ($market) {
            'DE', 'AT', 'IT' => [self::day($year, 12, 24), self::day($year, 12, 31)],
            'FI' => [
                self::day($year, 1, 6), self::easter($year, 39), self::midsummerEve($year),
                self::day($year, 12, 6), self::day($year, 12, 24), self::day($year, 12, 31),
            ],
            default => [],
        };
    }

    /**
     * Britain and Canada: Christmas or Boxing Day on a weekend moves to the
     * next free weekday.
     *
     * @return list<string>
     */
    private static function christmasAndBoxingDay(int $year): array
    {
        $days = [self::day($year, 12, 25), self::day($year, 12, 26)];

        return [...$days, ...match ((int) (new DateTimeImmutable(sprintf('%d-12-25', $year)))->format('N')) {
            5 => [self::day($year, 12, 28)],
            6 => [self::day($year, 12, 27), self::day($year, 12, 28)],
            7 => [self::day($year, 12, 27)],
            default => [],
        }];
    }

    /**
     * US rule for a fixed holiday: a Saturday is made up on the Friday before,
     * a Sunday on the Monday after.
     *
     * @return list<string>
     */
    private static function nearestWeekday(int $year, int $month, int $dayOfMonth): array
    {
        $date = new DateTimeImmutable(sprintf('%d-%02d-%02d', $year, $month, $dayOfMonth));

        return match ((int) $date->format('N')) {
            6 => [$date->format('Y-m-d'), $date->modify('-1 day')->format('Y-m-d')],
            7 => [$date->format('Y-m-d'), $date->modify('+1 day')->format('Y-m-d')],
            default => [$date->format('Y-m-d')],
        };
    }

    /** @return list<string> */
    private static function sundayToMonday(int $year, int $month, int $dayOfMonth): array
    {
        $date = new DateTimeImmutable(sprintf('%d-%02d-%02d', $year, $month, $dayOfMonth));

        return 7 === (int) $date->format('N')
            ? [$date->format('Y-m-d'), $date->modify('+1 day')->format('Y-m-d')]
            : [$date->format('Y-m-d')];
    }

    /** @return list<string> */
    private static function weekendToMonday(int $year, int $month, int $dayOfMonth): array
    {
        $date = new DateTimeImmutable(sprintf('%d-%02d-%02d', $year, $month, $dayOfMonth));

        return match ((int) $date->format('N')) {
            6 => [$date->format('Y-m-d'), $date->modify('+2 days')->format('Y-m-d')],
            7 => [$date->format('Y-m-d'), $date->modify('+1 day')->format('Y-m-d')],
            default => [$date->format('Y-m-d')],
        };
    }

    /** The Friday between 19 and 25 June. */
    private static function midsummerEve(int $year): string
    {
        $date = new DateTimeImmutable(sprintf('%d-06-19', $year));

        return $date->modify('+'.((5 - (int) $date->format('N') + 7) % 7).' days')->format('Y-m-d');
    }

    /** The n-th given weekday (1 = Monday) of a month. */
    private static function nthWeekday(int $year, int $month, int $weekday, int $n): string
    {
        $first = new DateTimeImmutable(sprintf('%d-%02d-01', $year, $month));
        $offset = ($weekday - (int) $first->format('N') + 7) % 7;

        return $first->modify('+'.($offset + 7 * ($n - 1)).' days')->format('Y-m-d');
    }

    /** The last given weekday (1 = Monday) of a month. */
    private static function lastWeekday(int $year, int $month, int $weekday): string
    {
        $last = (new DateTimeImmutable(sprintf('%d-%02d-01', $year, $month)))->modify('last day of this month');
        $offset = ((int) $last->format('N') - $weekday + 7) % 7;

        return $last->modify('-'.$offset.' days')->format('Y-m-d');
    }

    /** Easter Sunday plus an offset in days (anonymous Gregorian algorithm). */
    private static function easter(int $year, int $offset): string
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $dayOfMonth = (($h + $l - 7 * $m + 114) % 31) + 1;

        return (new DateTimeImmutable(sprintf('%d-%02d-%02d', $year, $month, $dayOfMonth)))
            ->modify(sprintf('%+d days', $offset))
            ->format('Y-m-d');
    }

    private static function day(int $year, int $month, int $dayOfMonth): string
    {
        return sprintf('%d-%02d-%02d', $year, $month, $dayOfMonth);
    }
}
