<?php

declare(strict_types=1);

namespace App\Import;

use App\Import\Degiro\DegiroHeader;

/**
 * Recognises an uploaded file by its structure alone, so the user never has to
 * tell the application which broker export they are handing over.
 *
 * Never by file name: a broker export renamed by the browser, or saved twice,
 * must still be read as what it is.
 */
final class FormatDetector
{
    public function detect(CsvSource $source): CsvFormat
    {
        $lines = $source->firstLines(40);
        if ([] === $lines) {
            return CsvFormat::Unknown;
        }

        // The Activity Statement holds several sections, so it is identified by
        // the title in its Statement section rather than by a header row. Only
        // the English title is recognised: the column names the importer maps
        // are English too, and a translated file is not guessed at.
        foreach ($lines as $line) {
            if ('Statement,Data,Title,Activity Statement' === rtrim(trim($line), ',')) {
                return CsvFormat::IbkrActivityStatement;
            }
        }

        $header = self::headerColumns($lines[0]);
        if ([] === $header) {
            return CsvFormat::Unknown;
        }

        return self::detectDegiro(new DegiroHeader($header));
    }

    /**
     * Both DEGIRO exports are localized, so they are identified by the *kind* of
     * columns they carry rather than by any one spelling:
     *
     *  - the transaction export is the one with a quantity and a price;
     *  - the account statement is the one with a value date and a description.
     *
     * Neither test can match the other file, and the ISIN column keeps them
     * apart from every non-DEGIRO format we support.
     */
    private static function detectDegiro(DegiroHeader $header): CsvFormat
    {
        if (!$header->has(DegiroHeader::ISIN) || !$header->has(DegiroHeader::DATE)) {
            return CsvFormat::Unknown;
        }

        if ($header->has(DegiroHeader::QUANTITY) && $header->has(DegiroHeader::PRICE)) {
            return CsvFormat::DegiroTransactions;
        }

        if ($header->has(DegiroHeader::DESCRIPTION)
            && ($header->has(DegiroHeader::VALUE_DATE) || count($header->indexesOf(DegiroHeader::DATE)) >= 2)) {
            return CsvFormat::DegiroAccount;
        }

        return CsvFormat::Unknown;
    }

    /**
     * @return list<string> lower-cased, unquoted column names
     */
    private static function headerColumns(string $line): array
    {
        $delimiter = CsvDelimiter::detect($line);

        $columns = str_getcsv($line, $delimiter, '"', '\\');

        $result = [];
        foreach ($columns as $column) {
            if (!is_string($column)) {
                continue;
            }

            $result[] = DegiroHeader::normalize($column);
        }

        return $result;
    }
}
