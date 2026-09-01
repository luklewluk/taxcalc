<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tax;

use App\Model\Dividend;
use App\Money\Amount;
use App\Tax\DividendTaxCalculator;
use App\Tax\TaxRates;
use App\Tests\Support\FixedExchange;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * PIT-38 part G declares aggregates, not a sum of per-payment roundings, so the
 * 19% has to stay exact per dividend and be rounded once at the total. Rounding
 * every micro-payment up to a grosz first would invent tax that is not due.
 */
#[CoversClass(DividendTaxCalculator::class)]
final class DividendRoundingTest extends TestCase
{
    private DividendTaxCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new DividendTaxCalculator(FixedExchange::create(), new TaxRates());
    }

    public function testManyMicroPaymentsDoNotAccumulateArtificialGrosze(): void
    {
        // 100 x 0.03 PLN gross. 19% of 0.03 is 0.0057 - rounding each payment to
        // a grosz first would give 100 x 0.01 = 1.00 instead of the true 0.57.
        $dividends = [];
        for ($i = 0; $i < 100; ++$i) {
            $dividends[] = self::plnDividend('0.03', '0');
        }

        $result = $this->calculator->calculate($dividends);

        self::assertSame('3.00', (string) $result->totalGross->value());
        self::assertSame('0.57', (string) $result->totalPolishTax->value());
        self::assertSame('0.57', (string) $result->conservative->taxDue->value());
        self::assertSame('1', (string) $result->conservative->taxDueRoundedToZloty->value());
    }

    public function testPerDividendPolishTaxKeepsFullPrecision(): void
    {
        $result = $this->calculator->calculate([self::plnDividend('0.03', '0')]);

        // Exact, not pre-rounded to 0.01.
        self::assertSame(0, $result->dividends[0]->polishTax->value()->compareTo(
            \App\Money\Decimal::of('0.0057'),
        ), (string) $result->dividends[0]->polishTax->value());
    }

    public function testCreditsAreAlsoSummedBeforeRounding(): void
    {
        // 19% of 0.03 = 0.0057; 15% treaty cap of 0.03 = 0.0045.
        $dividends = [];
        for ($i = 0; $i < 100; ++$i) {
            $dividends[] = self::plnDividend('0.03', '0.03');
        }

        $result = $this->calculator->calculate($dividends);

        // Conservative credit: 100 x 0.0045 = 0.45; due 0.57 - 0.45 = 0.12.
        self::assertSame('0.45', (string) $result->conservative->creditableTax->value());
        self::assertSame('0.12', (string) $result->conservative->taxDue->value());

        // NSA credit: min(0.03 withheld, 0.0057 Polish tax) per payment = 0.57.
        self::assertSame('0.57', (string) $result->nsa->creditableTax->value());
        self::assertSame('0.00', (string) $result->nsa->taxDue->value());
    }

    public function testHalfUpBoundaryAtTheDeclaredTotal(): void
    {
        // Gross 2.50 PLN -> 19% = 0.475 -> half-up to 0.48.
        $result = $this->calculator->calculate([self::plnDividend('2.50', '0')]);

        self::assertSame('0.48', (string) $result->totalPolishTax->value());
    }

    public function testRoundingHappensOnceFromTheExactValueNotInTwoSteps(): void
    {
        // Gross 2.63 PLN -> 19% = 0.4997 exactly.
        // Displayed in grosze that is 0.50, but the full-zloty figure must be
        // derived from 0.4997 (-> 0), not from the already-rounded 0.50 (-> 1).
        $result = $this->calculator->calculate([self::plnDividend('2.63', '0')]);

        self::assertSame('0.50', (string) $result->conservative->taxDue->value());
        self::assertSame('0', (string) $result->conservative->taxDueRoundedToZloty->value());
    }

    public function testHalfUpAtTheFullZlotyBoundary(): void
    {
        // 19% of 50.00 is exactly 9.50, which is the half-up boundary for the
        // full-zloty figure declared on the form.
        $result = $this->calculator->calculate([self::plnDividend('50.00', '0')]);

        self::assertSame('9.50', (string) $result->conservative->taxDue->value());
        self::assertSame('10', (string) $result->conservative->taxDueRoundedToZloty->value());
    }

    public function testTotalsAreConsistentWithTheSumOfExactPerRowValues(): void
    {
        $dividends = [
            self::plnDividend('11.11', '1.11'),
            self::plnDividend('22.22', '2.22'),
            self::plnDividend('33.33', '3.33'),
        ];

        $result = $this->calculator->calculate($dividends);

        $exact = \App\Money\Amount::zero('PLN');
        foreach ($result->dividends as $item) {
            $exact = $exact->plus($item->polishTax);
        }

        self::assertSame(
            (string) $exact->toScale(2)->value(),
            (string) $result->totalPolishTax->value(),
        );
    }

    private static function plnDividend(string $gross, string $withheld): Dividend
    {
        return new Dividend(
            'AAA',
            'US',
            'PLN',
            new DateTimeImmutable('2025-04-02'),
            Amount::of($gross, 'PLN'),
            Amount::of($withheld, 'PLN'),
            'test',
        );
    }
}
