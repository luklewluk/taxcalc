<?php

declare(strict_types=1);

namespace App\Report;

use App\CurrencyRate\ExchangeInterface;
use App\Exception\ExchangeRateUnavailableException;
use App\Model\ClosedPosition;
use App\Model\Dividend;
use App\Money\Amount;
use App\Tax\DividendTaxCalculator;
use App\Tax\StockTaxCalculator;
use App\Tax\TaxYearFilter;

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
     */
    public function build(array $positions, array $dividends, int $taxYear): TaxReport
    {
        $excludedPositions = $this->taxYearFilter->excludedPositionCount($positions, $taxYear);
        $excludedDividends = $this->taxYearFilter->excludedDividendCount($dividends, $taxYear);

        $yearPositions = $this->taxYearFilter->positionsForYear($positions, $taxYear);
        $yearDividends = $this->taxYearFilter->dividendsForYear($dividends, $taxYear);

        $errors = [];

        $convertible = [];
        foreach ($yearPositions as $position) {
            if (1 !== preg_match('/^[A-Z]{2}$/', $position->countryCode)) {
                $errors[] = sprintf(
                    'Pozycja %s nie ma poprawnego dwuliterowego kodu kraju. Uzupełnij kraj przed obliczeniem PIT/ZG.',
                    $position->name,
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

            $errors[] = sprintf(
                'Nie można przeliczyć pozycji %s (%s): %s Całe obliczenie zostało przerwane, aby nie zwrócić częściowego podatku.',
                $position->name,
                $position->sellDate->format('Y-m-d'),
                $failure,
            );
        }

        $convertibleDividends = [];
        foreach ($yearDividends as $dividend) {
            if (1 !== preg_match('/^[A-Z]{2}$/', $dividend->countryCode)) {
                $errors[] = sprintf(
                    'Dywidenda %s nie ma poprawnego dwuliterowego kodu kraju. Uzupełnij kraj przed obliczeniem PIT/ZG.',
                    $dividend->name,
                );

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

            $errors[] = sprintf(
                'Nie można przeliczyć dywidendy %s (%s): %s Całe obliczenie zostało przerwane, aby nie zwrócić częściowego podatku.',
                $dividend->name,
                $dividend->date->format('Y-m-d'),
                $failure,
            );
        }

        // Fail closed. Keep every diagnostic, but never calculate a partial tax
        // result after even one row failed currency conversion.
        if ([] !== $errors) {
            $convertible = [];
            $convertibleDividends = [];
        }

        $stock = $this->stockTaxCalculator->calculate($convertible);
        $dividendResult = $this->dividendTaxCalculator->calculate($convertibleDividends);

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
            $dividendResult->warnings(),
            $errors,
        );
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
