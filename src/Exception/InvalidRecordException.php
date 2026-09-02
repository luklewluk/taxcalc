<?php

declare(strict_types=1);

namespace App\Exception;

use App\Money\Amount;
use InvalidArgumentException;

/**
 * A normalized record broke a domain invariant.
 *
 * Messages are Polish because they are shown verbatim next to the offending
 * row in the review screen and in CLI output.
 */
final class InvalidRecordException extends InvalidArgumentException
{
    public static function amountMustBePositive(string $field, Amount $amount): self
    {
        return new self(sprintf(
            'Kwota "%s" musi być dodatnia, otrzymano %s %s.',
            $field,
            (string) $amount->value(),
            $amount->currency(),
        ));
    }

    public static function quantityMustBePositive(string $quantity): self
    {
        return new self(sprintf('Liczba sztuk musi być dodatnia, otrzymano %s.', $quantity));
    }

    public static function amountMustNotBeNegative(string $field, Amount $amount): self
    {
        return new self(sprintf(
            'Kwota "%s" nie może być ujemna, otrzymano %s %s.',
            $field,
            (string) $amount->value(),
            $amount->currency(),
        ));
    }

    public static function amountMustNotExceed(string $field, Amount $amount, string $limitField, Amount $limit): self
    {
        return new self(sprintf(
            'Kwota "%s" (%s %s) nie może przekraczać pola "%s" (%s %s).',
            $field,
            (string) $amount->value(),
            $amount->currency(),
            $limitField,
            (string) $limit->value(),
            $limit->currency(),
        ));
    }

    public static function currencyMismatch(string $field, string $expected, string $actual): self
    {
        return new self(sprintf(
            'Waluta pola "%s" (%s) nie zgadza się z walutą rekordu (%s).',
            $field,
            $actual,
            $expected,
        ));
    }

    public static function invalidCountryCode(string $code): self
    {
        return new self(sprintf(
            'Nieprawidłowy kod kraju "%s" - oczekiwano dwuliterowego kodu ISO 3166-1 alfa-2, np. US, IE, DE.',
            $code,
        ));
    }

    /**
     * Why the country is needed depends on what the record is.
     *
     * A closed position is settled under art. 30b and does go on the PIT/ZG
     * attachment. A dividend does not: it is taxed under art. 30a and reported
     * in part G of PIT-38 itself, and its country is needed only to pick the
     * treaty withholding cap that limits the foreign-tax credit. Telling the
     * user otherwise sends them looking for an attachment they must not file.
     */
    public static function countryRequired(string $name, bool $forPitZg = true): self
    {
        return new self(sprintf(
            $forPitZg
                ? 'Uzupełnij kraj uzyskania dochodu dla "%s" - jest wymagany do rozliczenia i do załącznika PIT/ZG.'
                : 'Uzupełnij kraj uzyskania dochodu dla "%s" - jest wymagany do ustalenia limitu stawki umownej '
                    .'przy odliczeniu podatku pobranego u źródła.',
            $name,
        ));
    }
}
