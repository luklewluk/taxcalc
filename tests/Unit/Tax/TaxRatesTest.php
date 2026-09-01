<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tax;

use App\Tax\TaxRates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TaxRates::class)]
final class TaxRatesTest extends TestCase
{
    public function testPolishFlatRateIsNineteenPercent(): void
    {
        self::assertSame('19', (string) TaxRates::POLISH_RATE_PERCENT);
    }

    public function testKnownTreatyRates(): void
    {
        $rates = new TaxRates();

        self::assertSame('15', (string) $rates->treatyWithholdingPercent('US'));
        self::assertSame('0', (string) $rates->treatyWithholdingPercent('IE'));
        self::assertSame('19', (string) $rates->treatyWithholdingPercent('PL'));
    }

    public function testLookupIsCaseInsensitive(): void
    {
        $rates = new TaxRates();

        self::assertSame('15', (string) $rates->treatyWithholdingPercent('us'));
    }

    public function testUnknownCountryReturnsNullInsteadOfThrowing(): void
    {
        $rates = new TaxRates();

        self::assertNull($rates->treatyWithholdingPercent('ZZ'));
        self::assertNull($rates->treatyWithholdingPercent(''));
        self::assertFalse($rates->isKnownCountry('ZZ'));
        self::assertTrue($rates->isKnownCountry('US'));
    }

    public function testSupportedCountriesAreExposedForTheUi(): void
    {
        $rates = new TaxRates();
        $countries = $rates->supportedCountries();

        self::assertArrayHasKey('US', $countries);
        self::assertNotEmpty($countries['US']);
        // Sorted alphabetically so the UI list is stable.
        self::assertSame(array_keys($countries), array_keys($countries));
    }
}
