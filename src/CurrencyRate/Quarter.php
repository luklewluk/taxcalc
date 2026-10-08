<?php

declare(strict_types=1);

namespace App\CurrencyRate;

use DateTimeImmutable;

/**
 * A calendar quarter - the unit in which NBP tables are fetched and stored.
 *
 * At most 92 days long, so one quarter always fits into a single table query
 * ({@see NbpApiRateProvider::MAX_RANGE_DAYS}).
 */
final readonly class Quarter
{
    private function __construct(
        public string $id,
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
    ) {
    }

    public static function of(DateTimeImmutable $day): self
    {
        $year = (int) $day->format('Y');
        $index = intdiv((int) $day->format('n') - 1, 3);
        $start = $day->setDate($year, $index * 3 + 1, 1)->setTime(0, 0);

        return new self(sprintf('%d-Q%d', $year, $index + 1), $start, $start->modify('+3 months -1 day'));
    }

    /**
     * Every quarter touching the days from `$first` to `$last`, in order.
     *
     * @return list<self>
     */
    public static function spanning(DateTimeImmutable $first, DateTimeImmutable $last): array
    {
        $quarters = [];
        for ($quarter = self::of($first); $quarter->start <= $last; $quarter = self::of($quarter->end->modify('+1 day'))) {
            $quarters[] = $quarter;
        }

        return $quarters;
    }
}
