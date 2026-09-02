<?php

declare(strict_types=1);

namespace App\Report;

use App\CurrencyRate\ExchangeInterface;
use App\Exception\ExchangeRateUnavailableException;
use App\Model\ClosedPosition;
use App\Model\Dividend;
use App\Model\AccountFee;
use App\Money\Amount;
use App\Money\Decimal;
use App\Tax\DividendTaxCalculator;
use App\Tax\StockTaxCalculator;
use App\Tax\TaxYearFilter;
use App\Web\Diagnostic;

/**
 * Turns normalized records into a settlement for one tax year.
 *
 * Rows are filtered to the chosen year first, then pre-flighted through the
 * exchange. A single unavailable NBP rate invalidates the whole calculation:
 * returning a plausible total from only the remaining rows would be more
 * dangerous than returning no result. Rate lookups are cached, so the
 * pre-flight costs nothing beyond the first call per currency and day.
 */
final readonly class TaxReportBuilder
{
    public function __construct(
        private TaxYearFilter $taxYearFilter,
        private StockTaxCalculator $stockTaxCalculator,
        private DividendTaxCalculator $dividendTaxCalculator,
        private ExchangeInterface $exchange,
    ) {
    }

    /**
     * @param list<ClosedPosition> $positions
     * @param list<Dividend>       $dividends
     * @param list<AccountFee>     $fees
     */
    public function build(array $positions, array $dividends, int $taxYear, array $fees = []): TaxReport
    {
        $excludedPositions = $this->taxYearFilter->excludedPositionCount($positions, $taxYear);
        $excludedDividends = $this->taxYearFilter->excludedDividendCount($dividends, $taxYear);
        $excludedFees = count(array_filter($fees, static fn (AccountFee $fee): bool => $fee->taxYear() !== $taxYear));

        $yearPositions = $this->taxYearFilter->positionsForYear($positions, $taxYear);
        $yearDividends = $this->taxYearFilter->dividendsForYear($dividends, $taxYear);
        $yearFees = array_values(array_filter($fees, static fn (AccountFee $fee): bool => $fee->taxYear() === $taxYear));
        [$yearFees, $feeWarnings, $feeErrors] = $this->normalizeFeeGroups($yearFees);

        $errors = $feeErrors;
        $diagnostics = [];
        foreach ($feeErrors as $message) {
            $diagnostics[] = Diagnostic::blocking('fee.invalid_group', $message, 'fees');
        }
        foreach ($feeWarnings as $message) {
            $diagnostics[] = Diagnostic::review('fee.reversed_group', $message, 'fees');
        }

        $convertible = [];
        foreach ($yearPositions as $position) {
            if (1 !== preg_match('/^[A-Z]{2}$/', $position->countryCode)) {
                $message = sprintf(
                    'Pozycja %s nie ma poprawnego dwuliterowego kodu kraju. Uzupełnij kraj przed obliczeniem PIT/ZG.',
                    $position->name,
                );
                $errors[] = $message;
                $diagnostics[] = Diagnostic::blocking(
                    'position.country_invalid',
                    $message,
                    'transactions',
                    $position->sellTradeId ?: ($position->buyTradeId ?: null),
                );

                continue;
            }

            $failure = $this->rateFailure(function () use ($position): void {
                $this->exchange->toPln($position->buyAmount, $position->buyDate);
                $this->exchange->toPln($position->sellAmount, $position->sellDate);
            });

            if (null === $failure) {
                $convertible[] = $position;

                continue;
            }

            $message = sprintf(
                'Nie można przeliczyć pozycji %s (%s): %s Całe obliczenie zostało przerwane, aby nie zwrócić częściowego podatku.',
                $position->name,
                $position->sellDate->format('Y-m-d'),
                $failure,
            );
            $errors[] = $message;
            $diagnostics[] = Diagnostic::blocking(
                'nbp.position_rate_unavailable',
                $message,
                'transactions',
                $position->sellTradeId ?: ($position->buyTradeId ?: null),
            );
        }

        $convertibleDividends = [];
        foreach ($yearDividends as $dividend) {
            if (1 !== preg_match('/^[A-Z]{2}$/', $dividend->countryCode)) {
                $message = sprintf(
                    'Dywidenda %s nie ma poprawnego dwuliterowego kodu kraju. Uzupełnij kraj, aby ustalić '
                    .'limit stawki umownej dla odliczenia podatku pobranego u źródła.',
                    $dividend->name,
                );
                $errors[] = $message;
                $diagnostics[] = Diagnostic::blocking('dividend.country_invalid', $message, 'dividends', $dividend->id());

                continue;
            }

            $failure = $this->rateFailure(function () use ($dividend): void {
                $this->exchange->toPln($dividend->grossAmount, $dividend->date);
                $this->exchange->toPln($dividend->withheldTax, $dividend->date);
            });

            if (null === $failure) {
                $convertibleDividends[] = $dividend;

                continue;
            }

            $message = sprintf(
                'Nie można przeliczyć dywidendy %s (%s): %s Całe obliczenie zostało przerwane, aby nie zwrócić częściowego podatku.',
                $dividend->name,
                $dividend->date->format('Y-m-d'),
                $failure,
            );
            $errors[] = $message;
            $diagnostics[] = Diagnostic::blocking('nbp.dividend_rate_unavailable', $message, 'dividends', $dividend->id());
        }

        $convertibleFees = [];
        foreach ($yearFees as $fee) {
            if (!$fee->included) {
                $convertibleFees[] = $fee;
                continue;
            }

            $failure = $this->rateFailure(function () use ($fee): void {
                $this->exchange->toPln($fee->amount, $fee->valueDate);
            });
            if (null === $failure) {
                $convertibleFees[] = $fee;
            } else {
                $message = sprintf(
                    'Nie można przeliczyć opłaty %s (%s): %s Całe obliczenie zostało przerwane, aby nie zwrócić częściowego podatku.',
                    $fee->description,
                    $fee->valueDate->format('Y-m-d'),
                    $failure,
                );
                $errors[] = $message;
                $diagnostics[] = Diagnostic::blocking('nbp.fee_rate_unavailable', $message, 'fees', $fee->id());
            }
        }

        // Fail closed. Keep every diagnostic, but never calculate a partial tax
        // result after even one row failed currency conversion.
        if ([] !== $errors) {
            $convertible = [];
            $convertibleDividends = [];
            $convertibleFees = [];
        }

        $stock = $this->stockTaxCalculator->calculate($convertible, $convertibleFees);
        $dividendResult = $this->dividendTaxCalculator->calculate($convertibleDividends);
        foreach ($dividendResult->dividends as $dividend) {
            if (null === $dividend->treatyPercent && null !== $dividend->warning) {
                $diagnostics[] = Diagnostic::review(
                    'dividend.treaty_rate_missing',
                    $dividend->warning,
                    'dividends',
                    $dividend->dividend->id(),
                );
            }
        }

        // The stock part is identical under both readings; only the dividend
        // credit is disputed, so the two totals differ by that alone.
        $conservative = $stock->tax->plus($dividendResult->conservative->taxDue);
        $nsa = $stock->tax->plus($dividendResult->nsa->taxDue);

        return new TaxReport(
            $taxYear,
            $stock,
            $dividendResult,
            $conservative->toScale(2),
            $conservative->toScale(0),
            $nsa->toScale(2),
            $nsa->toScale(0),
            $excludedPositions,
            $excludedDividends,
            $excludedFees,
            [...$dividendResult->warnings(), ...$feeWarnings],
            $errors,
            $diagnostics,
        );
    }

    /**
     * @param list<AccountFee> $fees
     *
     * @return array{list<AccountFee>, list<string>, list<string>} fees, warnings, errors
     */
    private function normalizeFeeGroups(array $fees): array
    {
        /** @var array<string, array{Decimal, Decimal, list<int>}> $groups */
        $groups = [];
        foreach ($fees as $index => $fee) {
            if (!$fee->included) {
                continue;
            }
            $key = $fee->currency.'|'.$fee->category;
            $groups[$key] ??= [Decimal::zero(), Decimal::zero(), []];
            $slot = $fee->correction ? 1 : 0;
            $groups[$key][$slot] = $groups[$key][$slot]->plus($fee->amount->value());
            $groups[$key][2][] = $index;
        }

        $drop = [];
        $warnings = [];
        $errors = [];
        foreach ($groups as $key => [$charges, $corrections, $indexes]) {
            $comparison = $corrections->compareTo($charges);
            if ($comparison > 0) {
                $errors[] = sprintf(
                    'Zwroty opłat w grupie %s przewyższają opłaty. Wyłącz lub popraw wadliwy wpis.',
                    str_replace('|', ' / ', $key),
                );
            } elseif (0 === $comparison && !$charges->isZero()) {
                foreach ($indexes as $index) {
                    $drop[$index] = true;
                }
                $warnings[] = sprintf(
                    'Pominięto wyzerowaną grupę opłat %s — korekty w całości odwracają opłaty.',
                    str_replace('|', ' / ', $key),
                );
            }
        }

        $kept = [];
        foreach ($fees as $index => $fee) {
            if (!isset($drop[$index])) {
                $kept[] = $fee;
            }
        }

        return [$kept, $warnings, $errors];
    }

    /**
     * @param callable():void $probe
     *
     * @return string|null the failure reason, or null when the rate is available
     */
    private function rateFailure(callable $probe): ?string
    {
        try {
            $probe();

            return null;
        } catch (ExchangeRateUnavailableException $e) {
            return $e->getMessage();
        }
    }
}
