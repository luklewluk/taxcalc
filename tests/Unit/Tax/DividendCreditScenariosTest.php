<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tax;

use App\Model\Dividend;
use App\Money\Amount;
use App\Tax\CreditMethod;
use App\Tax\DividendTaxCalculator;
use App\Tax\Result\DividendTaxResult;
use App\Tax\TaxRates;
use App\Tests\Support\FixedExchange;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * How much foreign withholding tax may be credited in Poland is genuinely
 * disputed, so the calculator produces both readings instead of picking one:
 *
 *  - conservative (KIS / tax-authority line): capped at the treaty rate;
 *  - NSA line (II FSK 1171/22, II FSK 1302/22): the tax actually withheld,
 *    capped only by the Polish 19% under art. 30a ust. 9.
 */
#[CoversClass(DividendTaxCalculator::class)]
final class DividendCreditScenariosTest extends TestCase
{
    private DividendTaxCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new DividendTaxCalculator(FixedExchange::create(), new TaxRates());
    }

    public function testUsDividendWithThirtyPercentWithheldProducesBothCredits(): void
    {
        // 100 USD gross at the fixed 4.0 rate = 400 PLN; 30 USD withheld = 120 PLN.
        $result = $this->calculate('US', '100.00', '30.00');
        $item = $result->dividends[0];

        self::assertSame('400.00', (string) $item->gross->pln->value());
        self::assertSame('120.00', (string) $item->withheldTax->pln->value());
        self::assertSame('76.00', (string) $item->polishTax->toScale(2)->value());

        // Conservative: treaty cap 15% of 400.
        self::assertSame('60.00', (string) $item->conservative->creditableTax->toScale(2)->value());
        self::assertSame('16.00', (string) $item->conservative->taxDue->toScale(2)->value());

        // NSA: the whole Polish 19% of 400 is absorbed by the 120 actually paid.
        self::assertSame('76.00', (string) $item->nsa->creditableTax->toScale(2)->value());
        self::assertSame('0.00', (string) $item->nsa->taxDue->toScale(2)->value());
    }

    public function testBothScenarioTotalsAreReported(): void
    {
        $result = $this->calculate('US', '100.00', '30.00');

        self::assertSame('60.00', (string) $result->conservative->creditableTax->value());
        self::assertSame('16.00', (string) $result->conservative->taxDue->value());
        self::assertSame('16', (string) $result->conservative->taxDueRoundedToZloty->value());

        self::assertSame('76.00', (string) $result->nsa->creditableTax->value());
        self::assertSame('0.00', (string) $result->nsa->taxDue->value());
        self::assertSame('0', (string) $result->nsa->taxDueRoundedToZloty->value());
    }

    public function testTheTwoScenariosAgreeWhenWithholdingIsBelowTheTreatyRate(): void
    {
        // 10% withheld is under both the 15% treaty cap and the 19% Polish tax.
        $result = $this->calculate('US', '100.00', '10.00');
        $item = $result->dividends[0];

        self::assertSame('40.00', (string) $item->conservative->creditableTax->toScale(2)->value());
        self::assertSame('40.00', (string) $item->nsa->creditableTax->toScale(2)->value());
        self::assertSame('36.00', (string) $item->conservative->taxDue->toScale(2)->value());
        self::assertSame('36.00', (string) $item->nsa->taxDue->toScale(2)->value());
    }

    public function testDifferenceBetweenScenariosIsFlagged(): void
    {
        $result = $this->calculate('US', '100.00', '30.00');

        self::assertSame('16.00', (string) $result->conservative->taxDue->toScale(2)->value());
        self::assertSame('0.00', (string) $result->nsa->taxDue->toScale(2)->value());
    }

    public function testNeitherScenarioEverCreditsMoreThanThePolishTax(): void
    {
        // Canada: 25% actually withheld, above both the 15% treaty cap and 19%.
        $result = $this->calculate('CA', '100.00', '25.00', 'CAD');
        $item = $result->dividends[0];

        // 100 CAD at 3.0 = 300 PLN gross, Polish tax 57.00.
        self::assertSame('57.00', (string) $item->polishTax->toScale(2)->value());
        self::assertSame('45.00', (string) $item->conservative->creditableTax->toScale(2)->value());
        self::assertSame('57.00', (string) $item->nsa->creditableTax->toScale(2)->value());
        self::assertSame('0.00', (string) $item->nsa->taxDue->toScale(2)->value());
    }

    public function testNoWithholdingMeansBothScenariosChargeTheFullNineteenPercent(): void
    {
        $result = $this->calculate('IE', '100.00', '0');

        self::assertSame('76.00', (string) $result->conservative->taxDue->value());
        self::assertSame('76.00', (string) $result->nsa->taxDue->value());
    }

    public function testUnknownCountryUsesZeroConservativeCreditAndActualTaxInNsaScenario(): void
    {
        $result = $this->calculate('ZZ', '100.00', '5.00');
        $item = $result->dividends[0];

        self::assertNotNull($item->warning);
        self::assertSame('0.00', (string) $item->conservative->creditableTax->toScale(2)->value());
        self::assertSame('76.00', (string) $item->conservative->taxDue->toScale(2)->value());
        self::assertSame('20.00', (string) $item->nsa->creditableTax->toScale(2)->value());
    }

    public function testCountryBreakdownCarriesBothScenarios(): void
    {
        $result = $this->calculator->calculate([
            self::dividend('US', 'USD', '2025-04-02', '100.00', '30.00'),
            self::dividend('US', 'USD', '2025-07-02', '100.00', '30.00'),
        ]);

        self::assertCount(1, $result->countries);
        $country = $result->countries[0];

        self::assertSame('US', $country->countryCode);
        self::assertSame('120.00', (string) $country->conservative->creditableTax->value());
        self::assertSame('32.00', (string) $country->conservative->taxDue->value());
        self::assertSame('152.00', (string) $country->nsa->creditableTax->value());
        self::assertSame('0.00', (string) $country->nsa->taxDue->value());
    }

    public function testMethodLabelsAreDistinctAndPolish(): void
    {
        self::assertNotSame(CreditMethod::Conservative->label(), CreditMethod::Nsa->label());
        self::assertStringContainsString('NSA', CreditMethod::Nsa->label().CreditMethod::Nsa->description());
        self::assertMatchesRegularExpression('/KIS|zachowawcz|umown/iu', CreditMethod::Conservative->label().CreditMethod::Conservative->description());
    }

    private function calculate(string $country, string $gross, string $withheld, string $currency = 'USD'): DividendTaxResult
    {
        return $this->calculator->calculate([self::dividend($country, $currency, '2025-04-02', $gross, $withheld)]);
    }

    private static function dividend(
        string $country,
        string $currency,
        string $date,
        string $gross,
        string $withheld,
    ): Dividend {
        return new Dividend(
            'AAA',
            $country,
            $currency,
            new DateTimeImmutable($date),
            Amount::of($gross, $currency),
            Amount::of($withheld, $currency),
            'test',
        );
    }
}
