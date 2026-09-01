<?php

declare(strict_types=1);

namespace App\Web;

/**
 * The settlement years the UI offers.
 *
 * Deliberately derived from the current date rather than hard-coded, so the
 * list keeps working next January without a release.
 */
final readonly class TaxYearProvider
{
    public function __construct(private int $firstTaxYear)
    {
    }

    /**
     * @return list<int> most recent first
     */
    public function years(): array
    {
        $current = (int) date('Y');
        $years = range($current, $this->firstTaxYear);

        return array_values($years);
    }

    /**
     * Most people settle the year that has just ended.
     */
    public function defaultYear(): int
    {
        return (int) date('Y') - 1;
    }

    public function normalize(mixed $value): int
    {
        $year = is_scalar($value) ? (int) $value : 0;

        return in_array($year, $this->years(), true) ? $year : $this->defaultYear();
    }
}
