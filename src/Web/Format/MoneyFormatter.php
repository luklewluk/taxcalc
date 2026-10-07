<?php

declare(strict_types=1);

namespace App\Web\Format;

use App\Money\Amount;
use App\Money\Decimal;

/**
 * Renders a domain amount the way a Polish reader expects it: a space between
 * thousands, a comma before the grosze, "zł" for the local currency.
 *
 * The conversion is purely textual. The value never becomes a float and never
 * loses a digit - the formatter reads the exact decimal string the domain
 * produced and only regroups it, so a figure carried at four decimal places
 * still prints all four. Rounding stays explicit, as everywhere else in the
 * domain: it happens only when a caller asks for a scale, and then through
 * {@see Decimal::toScale()}.
 *
 * Presentation only. Form fields and CSV exports keep the raw domain notation,
 * which is what a spreadsheet and a resubmitted form can read back.
 */
final readonly class MoneyFormatter
{
    /**
     * Non-breaking on purpose: a tax figure must never wrap across two lines.
     */
    public const string GROUP_SEPARATOR = "\u{00a0}";

    public const string DECIMAL_SEPARATOR = ',';

    private const string PLN_SYMBOL = 'zł';

    public function format(Amount|Decimal $value, ?int $scale = null): string
    {
        $decimal = $value instanceof Amount ? $value->value() : $value;

        if (null !== $scale) {
            $decimal = $decimal->toScale($scale);
        }

        return self::regroup((string) $decimal);
    }

    /**
     * A posted form value: formatted when it is a plain decimal, otherwise
     * shown exactly as typed - a summary row must never hide or "fix" input
     * the editor next to it will reject.
     */
    public function formatText(string $value): string
    {
        $trimmed = trim($value);

        return 1 === preg_match('/^-?\d+(\.\d+)?$/', $trimmed) ? $this->format(Decimal::of($trimmed)) : $value;
    }

    public function formatWithCurrency(Amount $amount, ?int $scale = null): string
    {
        return $this->format($amount, $scale).' '.$this->currencySymbol($amount->currency());
    }

    /**
     * The złoty gets its symbol; every other currency keeps its ISO code, which
     * is what the broker documents use and what makes a foreign leg unambiguous.
     */
    public function currencySymbol(string $currency): string
    {
        return 'PLN' === $currency ? self::PLN_SYMBOL : $currency;
    }

    private static function regroup(string $plain): string
    {
        $sign = str_starts_with($plain, '-') ? '-' : '';
        $digits = str_starts_with($plain, '-') || str_starts_with($plain, '+')
            ? substr($plain, 1)
            : $plain;

        $dot = strpos($digits, '.');
        $integer = false === $dot ? $digits : substr($digits, 0, $dot);
        $fraction = false === $dot ? '' : substr($digits, $dot + 1);

        // Grouping runs from the right, so the digits are chunked reversed and
        // put back in order. The separator is joined in afterwards - reversing a
        // string that already contained a multi-byte space would corrupt it.
        $chunks = array_map(strrev(...), str_split(strrev($integer), 3));

        return $sign
            .implode(self::GROUP_SEPARATOR, array_reverse($chunks))
            .('' === $fraction ? '' : self::DECIMAL_SEPARATOR.$fraction);
    }
}
