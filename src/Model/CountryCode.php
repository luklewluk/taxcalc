<?php

declare(strict_types=1);

namespace App\Model;

use App\Exception\InvalidRecordException;

/**
 * Syntactic rules for the country of origin.
 *
 * The code drives the PIT/ZG attachment and the withholding-tax credit, so a
 * typo like "USA" must not reach the calculator. Whether a *syntactically*
 * valid code is one we hold a treaty rate for is a separate question, answered
 * by {@see \App\Tax\TaxRates} with a warning rather than a rejection.
 *
 * Blank is deliberately allowed here: the flat Interactive Brokers exports do
 * not carry a country, and those rows have to reach the review screen so the
 * user can fill them in. The web form mapper is what refuses blank before a
 * result is produced.
 */
final class CountryCode
{
    public static function isValid(string $code): bool
    {
        return 1 === preg_match('/^[A-Z]{2}$/', $code);
    }

    /**
     * @throws InvalidRecordException when non-blank and malformed
     */
    public static function normalizeOptional(string $code): string
    {
        $normalized = strtoupper(trim($code));

        if ('' === $normalized) {
            return '';
        }

        if (!self::isValid($normalized)) {
            throw InvalidRecordException::invalidCountryCode($code);
        }

        return $normalized;
    }

    /**
     * @param bool $forPitZg whether this record feeds the PIT/ZG attachment,
     *                       which decides how the message explains itself
     *
     * @throws InvalidRecordException when blank or malformed
     */
    public static function normalizeRequired(string $code, string $recordName, bool $forPitZg = true): string
    {
        $normalized = strtoupper(trim($code));

        if ('' === $normalized) {
            throw InvalidRecordException::countryRequired($recordName, $forPitZg);
        }

        if (!self::isValid($normalized)) {
            throw InvalidRecordException::invalidCountryCode($code);
        }

        return $normalized;
    }
}
