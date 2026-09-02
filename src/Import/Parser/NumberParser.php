<?php

declare(strict_types=1);

namespace App\Import\Parser;

use App\Exception\InvalidNumberException;
use App\Money\Decimal;

/**
 * Turns the numeric notations found in broker exports and hand-edited CSVs into
 * exact decimals.
 *
 * The comma is ambiguous, so its meaning is a caller decision rather than a
 * guess: broker files group thousands with it, while a Polish user editing a
 * spreadsheet uses it as the decimal separator.
 *
 * When *both* separators occur the ambiguity resolves itself: the one that
 * appears last is the decimal point and the other groups thousands. That covers
 * the English `1,234.56` and the continental `1.431,00` that DEGIRO exports,
 * without the caller having to know which language the account was set to.
 * A value that fits neither notation is rejected rather than guessed at.
 */
final class NumberParser
{
    public static function parse(string $value, bool $decimalComma = false): Decimal
    {
        return Decimal::of(self::normalize($value, $decimalComma, false));
    }

    /**
     * Blank means "nothing was reported", which for optional columns such as
     * withholding tax is genuinely zero. Anything non-blank still has to parse.
     */
    public static function parseOrZero(string $value, bool $decimalComma = false): Decimal
    {
        if ('' === self::strip($value)) {
            return Decimal::zero();
        }

        return self::parse($value, $decimalComma);
    }

    /**
     * Parses a broker file whose decimal convention was inferred from all its
     * unambiguous numeric cells. Unlike the permissive form/normalized-CSV path,
     * this applies the convention symmetrically to dots and commas.
     */
    public static function parseLocalized(string $value, ?bool $decimalComma): Decimal
    {
        return Decimal::of(self::normalize($value, $decimalComma, true));
    }

    public static function parseLocalizedOrZero(string $value, ?bool $decimalComma): Decimal
    {
        if ('' === self::strip($value)) {
            return Decimal::zero();
        }

        return self::parseLocalized($value, $decimalComma);
    }

    private static function normalize(string $value, ?bool $decimalComma, bool $strictConvention): string
    {
        $normalized = self::strip($value);

        if ('' === $normalized) {
            throw InvalidNumberException::notADecimal($value);
        }

        // Accounting notation: (1.23) means -1.23.
        $negative = false;
        if (str_starts_with($normalized, '(') && str_ends_with($normalized, ')')) {
            $negative = true;
            $normalized = substr($normalized, 1, -1);
        }

        $hasComma = str_contains($normalized, ',');
        $hasDot = str_contains($normalized, '.');

        if ($hasComma && $hasDot) {
            // Both present: the separator that comes last is the decimal point,
            // the other one groups thousands.
            $decimalSeparator = strrpos($normalized, ',') > strrpos($normalized, '.') ? ',' : '.';
            $groupSeparator = ',' === $decimalSeparator ? '.' : ',';

            $normalized = self::ungroup($value, $normalized, $groupSeparator, $decimalSeparator);
        } elseif ($hasComma && $strictConvention) {
            $normalized = self::singleSeparator($value, $normalized, ',', $decimalComma);
        } elseif ($hasComma) {
            if (!$decimalComma) {
                $normalized = self::ungroup($value, $normalized, ',', null);
            } elseif (1 === preg_match('/^[+-]?\d+,\d+$/', $normalized)) {
                $normalized = str_replace(',', '.', $normalized);
            } else {
                throw InvalidNumberException::notADecimal($value);
            }
        } elseif ($hasDot && $strictConvention) {
            $normalized = self::singleSeparator($value, $normalized, '.', $decimalComma);
        }

        if ($negative) {
            $normalized = '-'.$normalized;
        }

        // Reject anything that is not a plain decimal by now (no exponents from
        // untrusted files, no stray letters, no leftover separators).
        if (1 !== preg_match('/^[+-]?(\d+(\.\d+)?|\.\d+)$/', $normalized)) {
            throw InvalidNumberException::notADecimal($value);
        }

        return ltrim($normalized, '+');
    }

    /**
     * Resolves a value containing only one kind of separator.
     *
     * `null` means that the caller deliberately has no file-wide convention.
     * Values such as `12,50` or `12.5000` still identify their own decimal
     * separator, while `1,234` is refused because it can mean either 1234 or
     * 1.234. DEGIRO's positional reader establishes the convention from the
     * other, unambiguous values in the same export before calling this method.
     *
     * @param bool|null $decimalComma true for comma decimals, false for dot
     *                                decimals, null when the file has no clue
     */
    private static function singleSeparator(
        string $original,
        string $normalized,
        string $separator,
        ?bool $decimalComma,
    ): string {
        $separatorIsDecimal = null === $decimalComma
            ? self::inferSingleSeparator($normalized, $separator)
            : (',' === $separator) === $decimalComma;

        if (null === $separatorIsDecimal) {
            throw InvalidNumberException::notADecimal($original);
        }

        if (!$separatorIsDecimal) {
            return self::ungroup($original, $normalized, $separator, null);
        }

        $quoted = preg_quote($separator, '/');
        if (1 !== preg_match('/^[+-]?\d+'.$quoted.'\d+$/', $normalized)) {
            throw InvalidNumberException::notADecimal($original);
        }

        return ',' === $separator ? str_replace(',', '.', $normalized) : $normalized;
    }

    /**
     * @return bool|null true when the separator is decimal, false when it is a
     *                   grouping mark, null when a single three-digit suffix is
     *                   genuinely ambiguous
     */
    private static function inferSingleSeparator(string $normalized, string $separator): ?bool
    {
        if (substr_count($normalized, $separator) > 1) {
            return false;
        }

        $at = strrpos($normalized, $separator);
        if (false === $at) {
            return null;
        }

        return 3 === strlen($normalized) - $at - 1 ? null : true;
    }

    /**
     * Removes a thousands separator, but only from a value that is actually
     * grouped in thousands.
     *
     * Dropping the separator unchecked is what makes `1,23` become 123 and
     * `12.34,56` become 1234.56 - a figure off by a factor of ten or a thousand,
     * with nothing in the output to show it happened. So the integer part has to
     * look like real grouping first: one to three digits, then groups of exactly
     * three. Anything else fits neither notation and is refused.
     *
     * @param string      $original          the untouched input, for the message
     * @param string|null $decimalSeparator  null when the value has no fraction
     *
     * @throws InvalidNumberException when the grouping is not consistent
     */
    private static function ungroup(
        string $original,
        string $normalized,
        string $groupSeparator,
        ?string $decimalSeparator,
    ): string {
        $sign = '';
        if ('' !== $normalized && ('+' === $normalized[0] || '-' === $normalized[0])) {
            $sign = $normalized[0];
            $normalized = substr($normalized, 1);
        }

        $fraction = '';
        if (null !== $decimalSeparator) {
            $at = strrpos($normalized, $decimalSeparator);
            if (false === $at) {
                throw InvalidNumberException::notADecimal($original);
            }

            $fraction = substr($normalized, $at + 1);
            $normalized = substr($normalized, 0, $at);
        }

        $group = preg_quote($groupSeparator, '/');
        if (1 !== preg_match('/^\d{1,3}('.$group.'\d{3})+$/', $normalized)) {
            throw InvalidNumberException::notADecimal($original);
        }

        $integer = str_replace($groupSeparator, '', $normalized);

        return $sign.$integer.(null === $decimalSeparator ? '' : '.'.$fraction);
    }

    private static function strip(string $value): string
    {
        // Normal, non-breaking and narrow no-break spaces are all used as
        // thousands separators in exported statements.
        $stripped = preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $value);

        return $stripped ?? '';
    }
}
