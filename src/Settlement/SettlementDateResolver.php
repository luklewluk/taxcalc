<?php

declare(strict_types=1);

namespace App\Settlement;

use App\Fifo\InstrumentKind;
use DateTimeImmutable;

/**
 * The day a trade leg settles under a {@see SettlementCycle}: n business days
 * after the trade, counted on the calendar of the market it was made on (or on
 * the Polish one). `null` under the trade-date cycle - the trade date stands.
 */
final readonly class SettlementDateResolver
{
    /** EU, EEA, Britain and Switzerland: T+2 from 6 October 2014, T+1 planned from 11 October 2027. */
    private const array EUROPE = [
        'AT', 'BE', 'BG', 'CH', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GB', 'GR', 'HR', 'HU', 'IE', 'IS',
        'IT', 'LI', 'LT', 'LU', 'LV', 'MT', 'NL', 'NO', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    public function settle(DateTimeImmutable $tradeDate, string $market, InstrumentKind $kind, SettlementCycle $cycle): ?DateTimeImmutable
    {
        if (SettlementCycle::TradeDate === $cycle) {
            return null;
        }

        [$calendar, $days] = SettlementCycle::PolishD2 === $cycle
            ? [HolidayCalendar::POLISH_STATUTORY, InstrumentKind::Option === $kind ? 1 : 2]
            : [$market, self::marketDays($tradeDate, $market, $kind)];

        $day = $tradeDate->setTime(0, 0);
        for ($counted = 0; $counted < $days;) {
            $day = $day->modify('+1 day');
            if (HolidayCalendar::isBusinessDay($day, $calendar)) {
                ++$counted;
            }
        }

        return $day;
    }

    /** Business days from trade to settlement on a market, by the date the trade was made. */
    public static function marketDays(DateTimeImmutable $tradeDate, string $market, InstrumentKind $kind): int
    {
        if (InstrumentKind::Option === $kind) {
            return 1;
        }

        $date = $tradeDate->format('Y-m-d');
        if (in_array($market, ['US', 'CA', 'MX'], true)) {
            if ($date >= ('US' === $market ? '2024-05-28' : '2024-05-27')) {
                return 1;
            }

            return 'MX' !== $market && $date < '2017-09-05' ? 3 : 2;
        }

        if (in_array($market, self::EUROPE, true)) {
            if ($date >= '2027-10-11') {
                return 1;
            }

            return $date < '2014-10-06' ? 3 : 2;
        }

        return 2;
    }
}
