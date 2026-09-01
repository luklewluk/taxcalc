<?php

declare(strict_types=1);

namespace App\Tax;

use App\Money\Decimal;

/**
 * Tax rates used by the calculator.
 *
 * `POLISH_RATE_PERCENT` is the flat 19% rate from art. 30a/30b of the Polish
 * PIT act and applies to both stock gains and dividends.
 *
 * The per-country table holds the *maximum withholding rate a double-taxation
 * treaty with Poland allows*. It is used only to cap the foreign tax credit:
 * if a broker withheld more than the treaty permits, the excess is not
 * creditable in Poland and has to be reclaimed from the source country.
 *
 * These values are a documented starting point, not tax advice - treaties get
 * renegotiated and the correct rate can depend on the instrument. Verify the
 * rate for your own case; see README.md ("Metodyka podatkowa").
 */
final readonly class TaxRates
{
    /**
     * Flat Polish rate on capital income, in percent.
     */
    public const string POLISH_RATE_PERCENT = '19';

    /**
     * Country code => [treaty withholding percent, human readable label].
     *
     * @var array<string, array{string, string}>
     */
    private const array TREATY_WITHHOLDING = [
        'AT' => ['15', 'Austria'],
        'AU' => ['15', 'Australia'],
        'BE' => ['15', 'Belgia'],
        'CA' => ['15', 'Kanada'],
        'CH' => ['15', 'Szwajcaria'],
        'CZ' => ['5', 'Czechy'],
        'DE' => ['15', 'Niemcy'],
        'DK' => ['15', 'Dania'],
        'ES' => ['15', 'Hiszpania'],
        'FI' => ['15', 'Finlandia'],
        'FR' => ['15', 'Francja'],
        'GB' => ['0', 'Wielka Brytania'],
        'HK' => ['10', 'Hongkong'],
        'IE' => ['0', 'Irlandia'],
        'IT' => ['10', 'Włochy'],
        'JP' => ['10', 'Japonia'],
        'LU' => ['15', 'Luksemburg'],
        'NL' => ['15', 'Holandia'],
        'NO' => ['15', 'Norwegia'],
        'PL' => ['19', 'Polska'],
        'PT' => ['15', 'Portugalia'],
        'SE' => ['15', 'Szwecja'],
        'SG' => ['10', 'Singapur'],
        'US' => ['15', 'Stany Zjednoczone'],
    ];

    public function polishRatePercent(): Decimal
    {
        return Decimal::of(self::POLISH_RATE_PERCENT);
    }

    /**
     * @return Decimal|null null when no treaty rate is configured for the country
     */
    public function treatyWithholdingPercent(string $countryCode): ?Decimal
    {
        $entry = self::TREATY_WITHHOLDING[strtoupper($countryCode)] ?? null;

        return null === $entry ? null : Decimal::of($entry[0]);
    }

    public function isKnownCountry(string $countryCode): bool
    {
        return isset(self::TREATY_WITHHOLDING[strtoupper($countryCode)]);
    }

    public function countryName(string $countryCode): ?string
    {
        return self::TREATY_WITHHOLDING[strtoupper($countryCode)][1] ?? null;
    }

    /**
     * Country code => label, alphabetically by code, for rendering in the UI.
     *
     * @return array<string, string>
     */
    public function supportedCountries(): array
    {
        return array_map(static fn (array $entry): string => $entry[1], self::TREATY_WITHHOLDING);
    }

    /**
     * Country code => treaty percent as a string, for the documentation table.
     *
     * @return array<string, string>
     */
    public function supportedRates(): array
    {
        return array_map(static fn (array $entry): string => $entry[0], self::TREATY_WITHHOLDING);
    }
}
