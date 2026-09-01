<?php

declare(strict_types=1);

namespace App\Report;

/**
 * Neutralises CSV formula injection.
 *
 * A spreadsheet treats a cell starting with `=`, `+`, `-`, `@`, a tab or a
 * carriage return as a formula, so an instrument name copied from a broker
 * statement could execute when the exported report is opened. Prefixing with an
 * apostrophe forces the cell to be read as text.
 *
 * Plain numbers are left alone, otherwise every negative amount would be
 * mangled into text and the report would stop being usable as a spreadsheet.
 */
final class CsvCell
{
    private const string DANGEROUS_PREFIXES = "=+-@\t\r";

    public static function safe(string $value): string
    {
        if ('' === $value) {
            return $value;
        }

        if (self::isPlainNumber($value)) {
            return $value;
        }

        if (!str_contains(self::DANGEROUS_PREFIXES, $value[0])) {
            return $value;
        }

        return "'".$value;
    }

    private static function isPlainNumber(string $value): bool
    {
        return 1 === preg_match('/^[+-]?\d+(\.\d+)?$/', $value);
    }
}
