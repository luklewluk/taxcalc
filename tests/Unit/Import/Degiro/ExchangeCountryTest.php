<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import\Degiro;

use App\Import\Degiro\ExchangeCountry;
use App\Model\CountryCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExchangeCountry::class)]
final class ExchangeCountryTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function listingCodes(): iterable
    {
        yield 'degiro nasdaq' => ['NDQ', 'US'];
        yield 'degiro nyse' => ['NSY', 'US'];
        yield 'degiro amsterdam' => ['EAM', 'NL'];
        yield 'degiro frankfurt' => ['FRA', 'DE'];
        yield 'degiro hong kong' => ['HKG', 'HK'];
        yield 'degiro warsaw' => ['WSE', 'PL'];
        yield 'mic nasdaq' => ['XNAS', 'US'];
        yield 'mic amsterdam' => ['XAMS', 'NL'];
        yield 'mic hong kong' => ['XHKG', 'HK'];
        yield 'mic london' => ['XLON', 'GB'];
    }

    #[DataProvider('listingCodes')]
    public function testAListingCodeResolvesToItsCountry(string $code, string $expected): void
    {
        self::assertSame($expected, ExchangeCountry::country($code));
    }

    public function testTheLookupIsCaseAndWhitespaceInsensitive(): void
    {
        self::assertSame('US', ExchangeCountry::country(' ndq '));
        self::assertSame('NL', ExchangeCountry::country("xams\n"));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function multiVenueCodes(): iterable
    {
        yield 'cboe europe' => ['CEUX'];
        yield 'turquoise' => ['TQEX'];
        yield 'aquis' => ['AQEU'];
        yield 'off exchange' => ['XOFF'];
    }

    /**
     * A pan-European MTF names no listing country, so it must not be allowed to
     * put the platform's own jurisdiction on the tax return.
     */
    #[DataProvider('multiVenueCodes')]
    public function testAMultiVenueCodeResolvesToNoCountry(string $code): void
    {
        self::assertSame('', ExchangeCountry::country($code));
        self::assertNotSame('', ExchangeCountry::name($code));
    }

    public function testAnUnknownCodeResolvesToNoCountryAndNoName(): void
    {
        self::assertSame('', ExchangeCountry::country('ZZZ'));
        self::assertSame('', ExchangeCountry::name('ZZZ'));
        self::assertSame('', ExchangeCountry::country(''));
    }

    /**
     * The table is hand-entered reference data, so every row has to name the
     * exchange it claims - a bare code => country pair cannot be reviewed by
     * eye, and a shape check cannot tell MX from XX.
     */
    public function testEveryEntryNamesAnExchangeAndAValidOrBlankCountry(): void
    {
        foreach (ExchangeCountry::all() as $code => [$country, $name]) {
            self::assertMatchesRegularExpression('/^[A-Z0-9]{3,4}$/', $code);
            self::assertNotSame('', trim($name), sprintf('Kod %s nie ma nazwy giełdy.', $code));
            self::assertTrue(
                '' === $country || CountryCode::isValid($country),
                sprintf('Kod %s ma nieprawidłowy kraj "%s".', $code, $country),
            );
        }
    }

    public function testEveryMappedCountryIsAlsoAnOfferableOption(): void
    {
        $countries = ExchangeCountry::countries();
        self::assertNotContains('', $countries);
        self::assertContains('US', $countries);
        self::assertSame(array_values(array_unique($countries)), $countries);
    }
}
