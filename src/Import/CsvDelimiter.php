<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Picks the field separator of a CSV line.
 *
 * Interactive Brokers uses commas; spreadsheets configured for a Polish locale
 * export semicolons. Whichever character yields more fields on the header row
 * wins, with a comma as the tie-breaking default.
 */
final class CsvDelimiter
{
    /**
     * @var list<non-empty-string>
     */
    private const array CANDIDATES = [',', ';', "\t"];

    /**
     * @return non-empty-string
     */
    public static function detect(string $headerLine): string
    {
        $best = ',';
        $bestCount = 0;

        foreach (self::CANDIDATES as $candidate) {
            $count = count(str_getcsv($headerLine, $candidate, '"', '\\'));
            if ($count > $bestCount) {
                $bestCount = $count;
                $best = $candidate;
            }
        }

        return $best;
    }
}
