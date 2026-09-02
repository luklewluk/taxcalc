<?php

declare(strict_types=1);

namespace App\Tax\Result;

use App\Money\Amount;

final readonly class StockTaxResult
{
    /**
     * @param list<CalculatedPosition>  $positions
     * @param list<CountryStockIncome>  $countries
     * @param list<CountryStockIncome>  $pitZgCountries countries with positive art. 30b income
     * @param list<CalculatedAccountFee> $accountingFees
     */
    public function __construct(
        public array $positions,
        public array $countries,
        public Amount $totalRevenue,
        public Amount $totalCost,
        public Amount $income,
        public Amount $loss,
        public Amount $tax,
        public Amount $taxRoundedToZloty,
        public array $pitZgCountries,
        public array $accountingFees,
        public Amount $accountingFeesCost,
        /** How much of `$totalCost` is the cost of disposal, so field 23 stays reconcilable. */
        public Amount $disposalCost,
    ) {
    }

    public function isLoss(): bool
    {
        return $this->loss->isPositive();
    }

    public function isEmpty(): bool
    {
        return [] === $this->positions && [] === $this->accountingFees;
    }
}
