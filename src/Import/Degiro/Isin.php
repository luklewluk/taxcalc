<?php

declare(strict_types=1);

namespace App\Import\Degiro;

use App\Model\CountryCode;

/**
 * The ISIN, which is all DEGIRO gives us to identify an instrument by.
 *
 * The check digit is deliberately *not* verified: refusing a real holding over
 * an arithmetic detail in a broker's own export would help nobody. The shape is
 * verified, because the two-letter prefix is what the country of origin is
 * inferred from and a malformed prefix would put nonsense on PIT/ZG.
 */
final class Isin
{
    private const string PATTERN = '/^[A-Z]{2}[A-Z0-9]{9}[0-9]$/';

    /**
     * Prefixes that name no jurisdiction: `X…` belongs to Euroclear and
     * Clearstream (Eurobonds, some ETFs), `EU` and `QZ` to supranational
     * issuers.
     *
     * @var list<string>
     */
    private const array NON_COUNTRY_PREFIXES = ['EU', 'QZ'];

    public static function isWellFormed(string $isin): bool
    {
        return 1 === preg_match(self::PATTERN, $isin);
    }

    /**
     * The country the prefix points at, or blank when it points at none.
     *
     * This is the country the *security* is registered in, which is not always
     * the country the income comes from - an Irish-domiciled ETF holding US
     * shares is the standard counter-example. Callers must present it as a
     * proposal for the user to confirm, never as a settled fact.
     */
    public static function country(string $isin): string
    {
        $prefix = mb_substr(mb_strtoupper($isin), 0, 2);

        if (str_starts_with($prefix, 'X') || in_array($prefix, self::NON_COUNTRY_PREFIXES, true)) {
            return '';
        }

        return CountryCode::isValid($prefix) ? $prefix : '';
    }
}
