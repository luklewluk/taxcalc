<?php

declare(strict_types=1);

namespace App\Import\Degiro;

/**
 * A DEGIRO export read positionally: the header plus rows of raw field values,
 * each row exactly as wide as the header.
 *
 * Rows are *not* keyed by column name. Both official DEGIRO CSVs repeat an
 * unnamed header for the currency of every amount, and a header-keyed reader
 * would keep only the last of those - silently pairing amounts with the wrong
 * currency.
 */
final readonly class DegiroTable
{
    /**
     * @param list<array{int, list<string>}> $rows      CSV line number and field values
     * @param non-empty-string               $delimiter the field separator detected in the file
     */
    public function __construct(
        public DegiroHeader $header,
        public array $rows,
        public string $delimiter = ',',
    ) {
    }

    /**
     * Whether numbers in this file use the comma as their decimal separator.
     *
     * The delimiter answers this without guessing. DEGIRO does not quote
     * numbers, so a comma inside a value simply cannot occur in a
     * comma-separated file - the field would have been split in two. A
     * semicolon-separated export is the continental one, where `1.431,00` means
     * one thousand four hundred and thirty-one.
     *
     * Values carrying both separators are unambiguous on their own and are
     * resolved by {@see \App\Import\Parser\NumberParser} either way.
     */
    public function decimalComma(): bool
    {
        return ',' !== $this->delimiter;
    }

    /**
     * @param list<string> $row
     */
    public function value(array $row, ?int $index): string
    {
        if (null === $index) {
            return '';
        }

        return trim($row[$index] ?? '');
    }
}
