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

    public function hasOptions(): bool
    {
        foreach ($this->positions as $calculated) {
            if ($calculated->position->isOption()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any position is a written option: only there does moving the
     * fee into the costs change the income (the writing fee keeps the writing
     * day's rate, the przychód takes the closing day's).
     */
    public function hasWrittenOptions(): bool
    {
        foreach ($this->positions as $calculated) {
            if ($calculated->position->isOption() && $calculated->position->isShort()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The options' share of field 22, summed from per-position figures that
     * were each rounded once - so it reconciles with the FIFO table.
     */
    public function optionRevenue(): Amount
    {
        $sum = Amount::zero('PLN')->toScale(2);
        foreach ($this->positions as $calculated) {
            if ($calculated->position->isOption()) {
                $sum = $sum->plus($calculated->revenue->pln);
            }
        }

        return $sum;
    }

    /**
     * The options' share of field 23: acquisition cost plus cost of disposal.
     */
    public function optionCost(): Amount
    {
        $sum = Amount::zero('PLN')->toScale(2);
        foreach ($this->positions as $calculated) {
            if ($calculated->position->isOption()) {
                $sum = $sum->plus($calculated->cost->pln);
                if (null !== $calculated->disposalCost) {
                    $sum = $sum->plus($calculated->disposalCost->pln);
                }
            }
        }

        return $sum;
    }
}
