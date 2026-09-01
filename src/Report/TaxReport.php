<?php

declare(strict_types=1);

namespace App\Report;

use App\Money\Amount;
use App\Tax\CreditMethod;
use App\Tax\Result\DividendTaxResult;
use App\Tax\Result\StockTaxResult;

/**
 * Everything needed to render or export a settlement for one tax year.
 *
 * There is no single "the tax" figure: how much foreign withholding tax may be
 * credited is legally disputed, so the total is reported under both readings
 * and every consumer is expected to show both.
 */
final readonly class TaxReport
{
    /**
     * @param list<string> $warnings
     * @param list<string> $errors
     */
    public function __construct(
        public int $taxYear,
        public StockTaxResult $stock,
        public DividendTaxResult $dividends,
        public Amount $totalTaxConservative,
        public Amount $totalTaxConservativeRounded,
        public Amount $totalTaxNsa,
        public Amount $totalTaxNsaRounded,
        public int $excludedPositions,
        public int $excludedDividends,
        public array $warnings,
        public array $errors,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->stock->isEmpty() && $this->dividends->isEmpty();
    }

    public function hasExclusions(): bool
    {
        return $this->excludedPositions > 0 || $this->excludedDividends > 0;
    }

    /**
     * True when the disputed treaty cap changes the bottom line. Only then does
     * the user actually have to take a position on it.
     */
    public function scenariosDiffer(): bool
    {
        return 0 !== $this->totalTaxConservative->compareTo($this->totalTaxNsa);
    }

    public function scenarioDifference(): Amount
    {
        return $this->totalTaxConservative->minus($this->totalTaxNsa);
    }

    public function totalTaxFor(CreditMethod $method): Amount
    {
        return match ($method) {
            CreditMethod::Conservative => $this->totalTaxConservative,
            CreditMethod::Nsa => $this->totalTaxNsa,
        };
    }
}
