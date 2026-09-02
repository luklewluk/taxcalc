<?php

declare(strict_types=1);

namespace App\Tax;

use App\Exception\InvalidRecordException;
use App\Model\Dividend;
use App\CurrencyRate\ExchangeInterface;
use App\Money\Amount;
use App\Tax\Result\CalculatedDividend;
use App\Tax\Result\CountryDividendIncome;
use App\Tax\Result\DividendCredit;
use App\Tax\Result\DividendTaxResult;
use App\Tax\Result\ScenarioTotals;

/**
 * PIT-38 part G: flat-rate tax on foreign dividends.
 *
 * Per dividend the Polish tax is 19% of the gross converted to PLN. How much of
 * the foreign withholding tax may be deducted from it is disputed, so both
 * readings are produced - see {@see CreditMethod}:
 *
 *   conservative (KIS): credit = min(withheld, treaty rate x gross, Polish tax)
 *   NSA line          : credit = min(withheld, Polish tax)
 *
 * Both are capped by the Polish tax, which is the one point art. 30a ust. 9
 * settles unambiguously.
 *
 * Amounts stay exact per dividend and are summed before rounding: the form
 * declares aggregates, and rounding each micro-payment first would invent
 * grosze that are not due.
 */
final readonly class DividendTaxCalculator
{
    private const int PLN_SCALE = 2;

    public function __construct(
        private ExchangeInterface $exchange,
        private TaxRates $taxRates,
    ) {
    }

    /**
     * @param list<Dividend> $dividends
     */
    public function calculate(array $dividends): DividendTaxResult
    {
        $calculated = [];
        $totalGross = Amount::zero('PLN');
        $totalWithheld = Amount::zero('PLN');
        $totalPolishTax = Amount::zero('PLN');
        $conservativeCredit = Amount::zero('PLN');
        $conservativeDue = Amount::zero('PLN');
        $nsaCredit = Amount::zero('PLN');
        $nsaDue = Amount::zero('PLN');

        foreach ($dividends as $dividend) {
            $entry = $this->calculateOne($dividend);
            $calculated[] = $entry;

            $totalGross = $totalGross->plus($entry->gross->pln);
            $totalWithheld = $totalWithheld->plus($entry->withheldTax->pln);
            $totalPolishTax = $totalPolishTax->plus($entry->polishTax);
            $conservativeCredit = $conservativeCredit->plus($entry->conservative->creditableTax);
            $conservativeDue = $conservativeDue->plus($entry->conservative->taxDue);
            $nsaCredit = $nsaCredit->plus($entry->nsa->creditableTax);
            $nsaDue = $nsaDue->plus($entry->nsa->taxDue);
        }

        return new DividendTaxResult(
            $calculated,
            $this->byCountry($calculated),
            $totalGross->toScale(self::PLN_SCALE),
            $totalWithheld->toScale(self::PLN_SCALE),
            $totalPolishTax->toScale(self::PLN_SCALE),
            new ScenarioTotals(
                CreditMethod::Conservative,
                $conservativeCredit->toScale(self::PLN_SCALE),
                $conservativeDue->toScale(self::PLN_SCALE),
                $conservativeDue->toScale(0),
            ),
            new ScenarioTotals(
                CreditMethod::Nsa,
                $nsaCredit->toScale(self::PLN_SCALE),
                $nsaDue->toScale(self::PLN_SCALE),
                $nsaDue->toScale(0),
            ),
        );
    }

    private function calculateOne(Dividend $dividend): CalculatedDividend
    {
        // Defence in depth: the model already rejects these, so reaching this
        // point means programmatic misuse rather than bad user input. Better a
        // loud exception than a plausible-looking tax figure.
        if (!$dividend->grossAmount->isPositive()) {
            throw InvalidRecordException::amountMustBePositive('kwota brutto', $dividend->grossAmount);
        }

        if ($dividend->withheldTax->isNegative()) {
            throw InvalidRecordException::amountMustNotBeNegative('podatek u źródła', $dividend->withheldTax);
        }

        $gross = $this->exchange->toPln($dividend->grossAmount, $dividend->date);
        $withheld = $this->exchange->toPln($dividend->withheldTax, $dividend->date);

        // Exact - not rounded here; the total is what gets rounded.
        $polishTax = $gross->pln->percentage($this->taxRates->polishRatePercent());

        $treatyPercent = $this->taxRates->treatyWithholdingPercent($dividend->countryCode);
        $warning = null;

        // NSA reading: whatever was actually withheld, up to the Polish tax.
        $nsaCredit = self::atMost($withheld->pln, $polishTax);

        // Conservative reading: additionally capped by the treaty rate.
        $conservativeCredit = $withheld->pln;
        if (null === $treatyPercent) {
            // Without a verified treaty rate, calling the actual withholding
            // "conservative" would be misleading and can overstate the credit.
            // Fail safe at zero while the separate NSA scenario still shows the
            // actual-tax reading capped at 19%.
            $conservativeCredit = Amount::zero('PLN');
            $warning = sprintf(
                'Brak skonfigurowanej stawki umownej dla kraju "%s". W wariancie zachowawczym '
                .'nie przyjęto odliczenia (0 PLN); wariant wg orzecznictwa NSA uwzględnia podatek '
                .'faktycznie pobrany do limitu 19%%. Zweryfikuj właściwą umowę lub skonsultuj rozliczenie.',
                $dividend->countryCode,
            );
        } else {
            $treatyCap = $gross->pln->percentage($treatyPercent);
            if ($conservativeCredit->compareTo($treatyCap) > 0) {
                $conservativeCredit = $treatyCap;
            }
        }

        $conservativeCredit = self::atMost($conservativeCredit, $polishTax);

        return new CalculatedDividend(
            $dividend,
            $gross,
            $withheld,
            $polishTax,
            new DividendCredit($conservativeCredit, self::due($polishTax, $conservativeCredit)),
            new DividendCredit($nsaCredit, self::due($polishTax, $nsaCredit)),
            $treatyPercent,
            $warning,
        );
    }

    /**
     * The credit can never exceed the Polish tax on the same income
     * (art. 30a ust. 9) - the one limit both readings agree on.
     */
    private static function atMost(Amount $credit, Amount $polishTax): Amount
    {
        return $credit->compareTo($polishTax) > 0 ? $polishTax : $credit;
    }

    private static function due(Amount $polishTax, Amount $credit): Amount
    {
        $due = $polishTax->minus($credit);

        return $due->isNegative() ? Amount::zero('PLN') : $due;
    }

    /**
     * @param list<CalculatedDividend> $dividends
     *
     * @return list<CountryDividendIncome>
     */
    private function byCountry(array $dividends): array
    {
        /** @var array<string, array{Amount, Amount, Amount, Amount, Amount, Amount, Amount}> $totals */
        $totals = [];

        foreach ($dividends as $calculated) {
            $code = strtoupper($calculated->dividend->countryCode);
            $totals[$code] ??= array_fill(0, 7, Amount::zero('PLN'));

            $totals[$code][0] = $totals[$code][0]->plus($calculated->gross->pln);
            $totals[$code][1] = $totals[$code][1]->plus($calculated->withheldTax->pln);
            $totals[$code][2] = $totals[$code][2]->plus($calculated->polishTax);
            $totals[$code][3] = $totals[$code][3]->plus($calculated->conservative->creditableTax);
            $totals[$code][4] = $totals[$code][4]->plus($calculated->conservative->taxDue);
            $totals[$code][5] = $totals[$code][5]->plus($calculated->nsa->creditableTax);
            $totals[$code][6] = $totals[$code][6]->plus($calculated->nsa->taxDue);
        }

        ksort($totals);

        $result = [];
        foreach ($totals as $code => $sums) {
            [$gross, $withheld, $polishTax, $consCredit, $consDue, $nsaCredit, $nsaDue] = $sums;

            $result[] = new CountryDividendIncome(
                $code,
                $this->taxRates->countryName($code),
                $gross->toScale(self::PLN_SCALE),
                $withheld->toScale(self::PLN_SCALE),
                $polishTax->toScale(self::PLN_SCALE),
                new DividendCredit(
                    $consCredit->toScale(self::PLN_SCALE),
                    $consDue->toScale(self::PLN_SCALE),
                ),
                new DividendCredit(
                    $nsaCredit->toScale(self::PLN_SCALE),
                    $nsaDue->toScale(self::PLN_SCALE),
                ),
            );
        }

        return $result;
    }
}
