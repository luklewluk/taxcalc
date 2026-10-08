<?php

declare(strict_types=1);

namespace App\Report;

use App\Money\Amount;
use App\Tax\CreditMethod;
use App\Tax\Result\DividendTaxResult;
use App\Tax\Result\StockTaxResult;
use App\Web\Diagnostic;

/**
 * Everything needed to render or export a settlement for one tax year.
 *
 * How much foreign withholding tax may be credited is legally disputed, so the
 * total is kept under both readings; the workbench setting picks the one every
 * surface shows ({@see totalTaxFor()}, {@see totalTaxRoundedFor()}).
 */
final readonly class TaxReport
{
    /**
     * @param list<string> $warnings
     * @param list<string> $errors
     * @param list<Diagnostic> $diagnostics
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
        public int $excludedFees,
        public array $warnings,
        public array $errors,
        public array $diagnostics = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->stock->isEmpty() && $this->dividends->isEmpty();
    }

    public function hasExclusions(): bool
    {
        return $this->excludedPositions > 0 || $this->excludedDividends > 0 || $this->excludedFees > 0;
    }

    /**
     * The full-zloty total for one reading - the figure that actually goes on
     * the form, as opposed to {@see totalTaxFor()} which keeps the grosze.
     */
    public function totalTaxRoundedFor(CreditMethod $method): Amount
    {
        return CreditMethod::Conservative === $method
            ? $this->totalTaxConservativeRounded
            : $this->totalTaxNsaRounded;
    }

    public function totalTaxFor(CreditMethod $method): Amount
    {
        return match ($method) {
            CreditMethod::Conservative => $this->totalTaxConservative,
            CreditMethod::Nsa => $this->totalTaxNsa,
        };
    }
}
