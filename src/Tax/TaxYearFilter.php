<?php

declare(strict_types=1);

namespace App\Tax;

use App\Model\ClosedPosition;
use App\Model\Dividend;

/**
 * Selects the records that belong to a settlement year.
 *
 * A position belongs to the year it *closed*, because that is when the income
 * is realised: the sale of a stock or a bought option, the closing buy (or the
 * expiry) of a written option. The opening leg may be arbitrarily older - which
 * is why the FIFO matcher never drops old lots. Dividends belong to the year
 * they were paid.
 */
final class TaxYearFilter
{
    /**
     * @param list<ClosedPosition> $positions
     *
     * @return list<ClosedPosition>
     */
    public function positionsForYear(array $positions, int $year): array
    {
        return array_values(array_filter($positions, static fn (ClosedPosition $p): bool => $p->taxYear() === $year));
    }

    /**
     * @param list<Dividend> $dividends
     *
     * @return list<Dividend>
     */
    public function dividendsForYear(array $dividends, int $year): array
    {
        return array_values(array_filter($dividends, static fn (Dividend $d): bool => $d->taxYear() === $year));
    }

    /**
     * Years in which anything was actually realised, most recent first.
     *
     * @param list<ClosedPosition> $positions
     * @param list<Dividend>       $dividends
     *
     * @return list<int>
     */
    public function availableYears(array $positions, array $dividends): array
    {
        $years = [];
        foreach ($positions as $position) {
            $years[$position->taxYear()] = true;
        }

        foreach ($dividends as $dividend) {
            $years[$dividend->taxYear()] = true;
        }

        $result = array_keys($years);
        rsort($result);

        return $result;
    }

    /**
     * @param list<ClosedPosition> $positions
     */
    public function excludedPositionCount(array $positions, int $year): int
    {
        return count($positions) - count($this->positionsForYear($positions, $year));
    }

    /**
     * @param list<Dividend> $dividends
     */
    public function excludedDividendCount(array $dividends, int $year): int
    {
        return count($dividends) - count($this->dividendsForYear($dividends, $year));
    }
}
