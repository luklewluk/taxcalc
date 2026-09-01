<?php

declare(strict_types=1);

namespace App\Tax\Result;

use App\Money\Amount;

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
    public function scenariosDiffer(): bool
    {
        return 0 !== $this->conservative->taxDue->compareTo($this->nsa->taxDue);
    }

    public function scenarioDifference(): Amount
    {
        return $this->conservative->taxDue->minus($this->nsa->taxDue);
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
