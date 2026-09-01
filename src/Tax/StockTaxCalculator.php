<?php

declare(strict_types=1);

namespace App\Tax;

use App\CurrencyRate\ExchangeInterface;
use App\Model\ClosedPosition;
use App\Money\Amount;
use App\Tax\Result\CalculatedPosition;
use App\Tax\Result\CountryStockIncome;
use App\Tax\Result\StockTaxResult;

/**
 * PIT-38 part C: income from the paid disposal of securities.
 *
 * Each leg is converted with the NBP rate of the business day before *that
 * leg's* own transaction date, then revenue and cost are summed. The 19% tax is
 * charged on the aggregate income; a loss produces zero tax (it is carried
 * forward by the taxpayer, which this tool does not attempt to track).
 */
final readonly class StockTaxCalculator
{
    private const int PLN_SCALE = 2;

    public function __construct(
        private ExchangeInterface $exchange,
        private TaxRates $taxRates = new TaxRates(),
    ) {
    }

    /**
     * @param list<ClosedPosition> $positions
     */
    public function calculate(array $positions): StockTaxResult
    {
        $calculated = [];
        $totalRevenue = Amount::zero('PLN');
        $totalCost = Amount::zero('PLN');

        foreach ($positions as $position) {
            $cost = $this->exchange->toPln($position->buyAmount, $position->buyDate);
            $revenue = $this->exchange->toPln($position->sellAmount, $position->sellDate);

            $calculated[] = new CalculatedPosition(
                $position,
                $cost,
                $revenue,
                $revenue->pln->minus($cost->pln),
            );

            $totalRevenue = $totalRevenue->plus($revenue->pln);
            $totalCost = $totalCost->plus($cost->pln);
        }

        $income = $totalRevenue->minus($totalCost);
        $profit = $income->isNegative() ? Amount::zero('PLN') : $income;
        $loss = $income->isNegative() ? $income->negated() : Amount::zero('PLN');

        // Kept exact so both declared figures below are rounded once, from the
        // same value. Rounding to grosze first and then to zloty would round
        // twice and can shift the declared amount by a whole zloty.
        $exactTax = $profit->percentage($this->taxRates->polishRatePercent());

        return new StockTaxResult(
            $calculated,
            $this->byCountry($calculated),
            $totalRevenue->toScale(self::PLN_SCALE),
            $totalCost->toScale(self::PLN_SCALE),
            $income->toScale(self::PLN_SCALE),
            $loss->toScale(self::PLN_SCALE),
            $exactTax->toScale(self::PLN_SCALE),
            // PIT amounts are declared in full zloty.
            $exactTax->toScale(0),
        );
    }

    /**
     * @param list<CalculatedPosition> $positions
     *
     * @return list<CountryStockIncome>
     */
    private function byCountry(array $positions): array
    {
        /** @var array<string, array{Amount, Amount}> $totals */
        $totals = [];

        foreach ($positions as $calculated) {
            $code = strtoupper($calculated->position->countryCode);
            $totals[$code] ??= [Amount::zero('PLN'), Amount::zero('PLN')];
            $totals[$code][0] = $totals[$code][0]->plus($calculated->revenue->pln);
            $totals[$code][1] = $totals[$code][1]->plus($calculated->cost->pln);
        }

        ksort($totals);

        $result = [];
        foreach ($totals as $code => [$revenue, $cost]) {
            $result[] = new CountryStockIncome(
                $code,
                $this->taxRates->countryName($code),
                $revenue->toScale(self::PLN_SCALE),
                $cost->toScale(self::PLN_SCALE),
                $revenue->minus($cost)->toScale(self::PLN_SCALE),
            );
        }

        return $result;
    }
}
