<?php

declare(strict_types=1);

namespace App\Tax\Result;

use App\Money\Amount;

final readonly class StockTaxResult
{
    /**
     * @param list<CalculatedPosition>  $positions
     * @param list<CountryStockIncome>  $countries
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
    ) {
    }

    public function isLoss(): bool
    {
        return $this->loss->isPositive();
    }

    public function isEmpty(): bool
    {
        return [] === $this->positions;
    }
}
