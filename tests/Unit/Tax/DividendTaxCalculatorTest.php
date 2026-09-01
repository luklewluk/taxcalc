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

#[CoversClass(DividendTaxCalculator::class)]
final class DividendTaxCalculatorTest extends TestCase
{
    private DividendTaxCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new DividendTaxCalculator(FixedExchange::create(), new TaxRates());
    }

    public function testUsDividendWithFifteenPercentWithheldLeavesFourPercentToPay(): void
    {
        // 100 USD * 4.0 = 400 PLN gross; PL tax 19% = 76.00; withheld 15 USD -> 60.00 PLN
        $result = $this->calculator->calculate([self::dividend('US', 'USD', '2024-06-10', '100.00', '15.00')]);

        $calculated = $result->dividends[0];
        self::assertSame('400.00', (string) $calculated->gross->pln->value());
        self::assertSame('76.00', (string) $calculated->polishTax->toScale(2)->value());
        self::assertSame('60.00', (string) $calculated->withheldTax->pln->value());
        self::assertSame('60.00', (string) $calculated->conservative->creditableTax->toScale(2)->value());
        self::assertSame('16.00', (string) $calculated->conservative->taxDue->toScale(2)->value());
        self::assertNull($calculated->warning);
    }

    public function testCreditIsCappedAtTheTreatyRateWhenBrokerWithheldMore(): void
    {
        // 30 USD withheld = 120 PLN, but the PL-US treaty caps the credit at 15% = 60 PLN
        $result = $this->calculator->calculate([self::dividend('US', 'USD', '2024-06-10', '100.00', '30.00')]);

        $calculated = $result->dividends[0];
        self::assertSame('120.00', (string) $calculated->withheldTax->pln->value());
        self::assertSame('60.00', (string) $calculated->conservative->creditableTax->toScale(2)->value());
        self::assertSame('16.00', (string) $calculated->conservative->taxDue->toScale(2)->value());
        self::assertNotNull($calculated->warning);
    }

    public function testCreditBelowTheTreatyRateIsTakenAtItsActualValue(): void
    {
        // Only 10% withheld -> credit is the actual 40 PLN, not the 60 PLN cap
        $result = $this->calculator->calculate([self::dividend('US', 'USD', '2024-06-10', '100.00', '10.00')]);

        $calculated = $result->dividends[0];
        self::assertSame('40.00', (string) $calculated->conservative->creditableTax->toScale(2)->value());
        self::assertSame('36.00', (string) $calculated->conservative->taxDue->toScale(2)->value());
    }

    public function testNothingWithheldMeansTheFullNineteenPercentIsDue(): void
    {
        $result = $this->calculator->calculate([self::dividend('IE', 'USD', '2024-06-10', '100.00', '0.00')]);

        $calculated = $result->dividends[0];
        self::assertSame('0.00', (string) $calculated->conservative->creditableTax->toScale(2)->value());
        self::assertSame('76.00', (string) $calculated->conservative->taxDue->toScale(2)->value());
    }

    public function testCreditNeverExceedsThePolishTaxSoTaxDueStaysAtZero(): void
    {
        // Canada: 25% actually withheld, treaty cap 15%, and the Polish tax is 19%.
        $result = $this->calculator->calculate([self::dividend('CA', 'CAD', '2024-06-10', '100.00', '25.00')]);

        $calculated = $result->dividends[0];
        self::assertSame('300.00', (string) $calculated->gross->pln->value());
        self::assertSame('57.00', (string) $calculated->polishTax->toScale(2)->value());
        self::assertSame('45.00', (string) $calculated->conservative->creditableTax->toScale(2)->value());
        self::assertSame('12.00', (string) $calculated->conservative->taxDue->toScale(2)->value());
        self::assertFalse($calculated->conservative->taxDue->isNegative());
    }

    public function testUnknownCountryIsReportedAsAWarningInsteadOfThrowing(): void
    {
        $result = $this->calculator->calculate([self::dividend('ZZ', 'USD', '2024-06-10', '100.00', '5.00')]);

        $calculated = $result->dividends[0];
        self::assertNotNull($calculated->warning);
        self::assertStringContainsString('ZZ', $calculated->warning);
        // Without a verified treaty rate the conservative scenario must not
        // guess a credit. The NSA scenario still reflects actual withholding.
        self::assertSame('0.00', (string) $calculated->conservative->creditableTax->toScale(2)->value());
        self::assertSame('76.00', (string) $calculated->conservative->taxDue->toScale(2)->value());
        self::assertCount(1, $result->warnings());
    }

    public function testPlnDividendNeedsNoRate(): void
    {
        $result = $this->calculator->calculate([self::dividend('PL', 'PLN', '2024-06-10', '100.00', '19.00')]);

        $calculated = $result->dividends[0];
        self::assertSame('1', (string) $calculated->gross->rate);
        self::assertSame('19.00', (string) $calculated->conservative->creditableTax->toScale(2)->value());
        self::assertSame('0.00', (string) $calculated->conservative->taxDue->toScale(2)->value());
    }

    public function testTotalsAndCountryBreakdown(): void
    {
        $result = $this->calculator->calculate([
            self::dividend('US', 'USD', '2024-06-10', '100.00', '15.00'),
            self::dividend('US', 'USD', '2024-09-10', '50.00', '7.50'),
            self::dividend('IE', 'USD', '2024-09-10', '20.00', '0.00'),
        ]);

        self::assertSame('680.00', (string) $result->totalGross->value());
        self::assertSame('129.20', (string) $result->totalPolishTax->value());
        self::assertSame('90.00', (string) $result->conservative->creditableTax->value());
        self::assertSame('39.20', (string) $result->conservative->taxDue->value());
        self::assertSame('39', (string) $result->conservative->taxDueRoundedToZloty->value());

        $byCountry = [];
        foreach ($result->countries as $country) {
            $byCountry[$country->countryCode] = $country;
        }

        self::assertSame('600.00', (string) $byCountry['US']->gross->value());
        self::assertSame('90.00', (string) $byCountry['US']->withheldTax->value());
        self::assertSame('80.00', (string) $byCountry['IE']->gross->value());
        self::assertSame('0.00', (string) $byCountry['IE']->withheldTax->value());
    }

    public function testEmptyInputProducesZeroedResult(): void
    {
        $result = $this->calculator->calculate([]);

        self::assertSame([], $result->dividends);
        self::assertSame('0.00', (string) $result->conservative->taxDue->value());
    }

    private static function dividend(
        string $country,
        string $currency,
        string $date,
        string $gross,
        string $withheld,
    ): Dividend {
        return new Dividend(
            'TICKER',
            $country,
            $currency,
            new DateTimeImmutable($date),
            Amount::of($gross, $currency),
            Amount::of($withheld, $currency),
            'test',
        );
    }
}
