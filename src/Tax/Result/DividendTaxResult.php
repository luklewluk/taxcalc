<?php

declare(strict_types=1);

namespace App\Tax\Result;

use App\Money\Amount;
use App\Tax\CreditMethod;

final readonly class DividendTaxResult
{
    /**
     * @param list<CalculatedDividend>    $dividends
     * @param list<CountryDividendIncome> $countries
     */
    public function __construct(
        public array $dividends,
        public array $countries,
        public Amount $totalGross,
        public Amount $totalWithheldTax,
        public Amount $totalPolishTax,
        public ScenarioTotals $conservative,
        public ScenarioTotals $nsa,
    ) {
    }

    /**
     * True when the disputed treaty cap actually changes the outcome, which is
     * the only time the user needs to make a decision about it.
     */
    /**
     * The totals for one reading, so a caller that has already decided which
     * variant it declares does not have to branch on the enum itself.
     */
    public function scenarioFor(CreditMethod $method): ScenarioTotals
    {
        return CreditMethod::Conservative === $method ? $this->conservative : $this->nsa;
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = [];
        foreach ($this->dividends as $dividend) {
            if (null !== $dividend->warning) {
                $warnings[] = $dividend->warning;
            }
        }

        return array_values(array_unique($warnings));
    }

    public function isEmpty(): bool
    {
        return [] === $this->dividends;
    }
}
