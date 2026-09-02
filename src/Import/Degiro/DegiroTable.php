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
        private ?bool $decimalComma = null,
    ) {
    }

    /**
     * The convention inferred from unambiguous numbers throughout the file.
     * `null` means every number was integral or ambiguous on its own; a caller
     * may still parse integers, but must refuse a value such as `1,234`.
     */
    public function decimalComma(): ?bool
    {
        return $this->decimalComma;
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
