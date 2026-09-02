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
        // The workbench supports the published 2021-2025 forms and a clearly
        // marked provisional 2026 mapping. Later years wait for an update.
        $current = min(2026, (int) date('Y'));
        $years = range($current, $this->firstTaxYear);

        return array_values($years);
    }

    /**
     * Most people settle the year that has just ended.
     */
    public function defaultYear(): int
    {
        return min(2025, (int) date('Y') - 1);
    }

    public function normalize(mixed $value): int
    {
        $year = is_scalar($value) ? (int) $value : 0;

        return in_array($year, $this->years(), true) ? $year : $this->defaultYear();
    }
}
