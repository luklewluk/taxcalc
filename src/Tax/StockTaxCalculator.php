<?php

declare(strict_types=1);

namespace App\Tax;

use App\CurrencyRate\ExchangeInterface;
use App\Model\ClosedPosition;
use App\Model\AccountFee;
use App\Money\Amount;
use App\Tax\Result\CalculatedPosition;
use App\Tax\Result\CountryStockIncome;
use App\Tax\Result\StockTaxResult;
use App\Tax\Result\CalculatedAccountFee;

/**
 * PIT-38 part C: income from the paid disposal of securities.
 *
 * Each leg is converted with the NBP rate of the business day before *that
 * leg's* own transaction date, then revenue and cost are summed. The 19% tax is
 * charged on the aggregate income; a loss produces zero tax (it is carried
 * forward by the taxpayer, which this tool does not attempt to track).
 *
 * The declared przychód is the gross amount due, not the settled cash: a broker
 * reports proceeds net of its sell fee, and that fee belongs in the costs
 * instead. For stocks and bought options income is unaffected by the
 * reallocation - only the split between the two declared fields.
 *
 * Options settle when the position closes (art. 17 ust. 1b). The przychód is
 * therefore converted at the rate before {@see ClosedPosition::revenueDate()},
 * which for a written option is the closing buy, not the day the premium was
 * received; each cost keeps the rate of the day it was paid (art. 11a ust. 2),
 * so the commission on writing an option uses the writing day's rate.
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
     * @param list<AccountFee> $fees already filtered to the selected year
     */
    public function calculate(array $positions, array $fees = []): StockTaxResult
    {
        $calculated = [];
        $totalRevenue = Amount::zero('PLN');
        $totalCost = Amount::zero('PLN');
        $calculatedFees = [];
        $feeCost = Amount::zero('PLN');
        $disposalCost = Amount::zero('PLN');

        foreach ($positions as $position) {
            // The declared przychód is the *kwota należna* - the settled cash
            // with the broker's sell fee added back - and that same fee is a
            // koszt odpłatnego zbycia. The buy fee needs nothing: it is already
            // inside buyAmount, which is the cash that left the account.
            //
            // Each declared figure is one exact conversion rounded once, rather
            // than a sum of two roundings, because toPln() rounds to grosze
            // every time: revenue->original * revenue->rate has to equal
            // revenue->pln, which is the multiplication the FIFO row invites the
            // reader to redo from its own cells.
            $disposalFee = $position->disposalFee();
            $grossProceeds = null === $disposalFee
                ? $position->sellAmount
                : $position->sellAmount->plus($disposalFee);

            $cost = $this->exchange->toPln($position->buyAmount, $position->buyRateDate());
            $revenue = $this->exchange->toPln($grossProceeds, $position->revenueDate());
            $disposal = null === $disposalFee
                ? null
                : $this->exchange->toPln($disposalFee, $position->sellRateDate());

            $positionCost = null === $disposal ? $cost->pln : $cost->pln->plus($disposal->pln);

            $calculated[] = new CalculatedPosition(
                $position,
                $cost,
                $revenue,
                $revenue->pln->minus($positionCost),
                $disposal,
            );

            $totalRevenue = $totalRevenue->plus($revenue->pln);
            $totalCost = $totalCost->plus($positionCost);
            if (null !== $disposal) {
                $disposalCost = $disposalCost->plus($disposal->pln);
            }
        }

        foreach ($fees as $fee) {
            if (!$fee->included) {
                continue;
            }

            $exchanged = $this->exchange->toPln($fee->amount, $fee->valueDate);
            $impact = $fee->correction ? $exchanged->pln->negated() : $exchanged->pln;
            $calculatedFees[] = new CalculatedAccountFee($fee, $exchanged, $impact);
            $feeCost = $feeCost->plus($impact);
        }

        $totalCost = $totalCost->plus($feeCost);

        $income = $totalRevenue->minus($totalCost);
        $profit = $income->isNegative() ? Amount::zero('PLN') : $income;
        $loss = $income->isNegative() ? $income->negated() : Amount::zero('PLN');

        // Kept exact so both declared figures below are rounded once, from the
        // same value. Rounding to grosze first and then to zloty would round
        // twice and can shift the declared amount by a whole zloty.
        $exactTax = $profit->percentage($this->taxRates->polishRatePercent());

        $countries = $this->byCountry($calculated);

        return new StockTaxResult(
            $calculated,
            $countries,
            $totalRevenue->toScale(self::PLN_SCALE),
            $totalCost->toScale(self::PLN_SCALE),
            $income->toScale(self::PLN_SCALE),
            $loss->toScale(self::PLN_SCALE),
            $exactTax->toScale(self::PLN_SCALE),
            // PIT amounts are declared in full zloty.
            $exactTax->toScale(0),
            array_values(array_filter(
                $countries,
                static fn (CountryStockIncome $country): bool => $country->income->isPositive(),
            )),
            $calculatedFees,
            $feeCost->toScale(self::PLN_SCALE),
            $disposalCost->toScale(self::PLN_SCALE),
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
            $totals[$code][1] = $totals[$code][1]->plus($calculated->totalCost());
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
