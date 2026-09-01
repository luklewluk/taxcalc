<?php

declare(strict_types=1);

namespace App\Report;

use App\Model\ClosedPosition;
use App\Model\Dividend;
use League\Csv\Writer;

/**
 * Writes the tool's own normalized CSV formats.
 *
 * The column names and their order are a public contract - files produced by
 * the very first CLI version still import, and files produced today still work
 * with any tooling built around that layout.
 */
final class NormalizedCsvWriter
{
    /**
     * @var list<string>
     */
    public const array POSITION_HEADER = [
        'name', 'country', 'currency', 'buy_date', 'buy_total_amount', 'sell_date', 'sell_total_amount',
    ];

    /**
     * @var list<string>
     */
    public const array DIVIDEND_HEADER = ['name', 'country', 'currency', 'date', 'amount', 'tax_paid'];

    /**
     * @param list<ClosedPosition> $positions
     */
    public function writePositions(array $positions): string
    {
        $writer = Writer::fromString();
        $writer->insertOne(self::POSITION_HEADER);

        foreach ($positions as $position) {
            $writer->insertOne(array_map(CsvCell::safe(...), [
                $position->name,
                $position->countryCode,
                $position->currency,
                $position->buyDate->format('Y-m-d'),
                (string) $position->buyAmount->value(),
                $position->sellDate->format('Y-m-d'),
                (string) $position->sellAmount->value(),
            ]));
        }

        return $writer->toString();
    }

    /**
     * @param list<Dividend> $dividends
     */
    public function writeDividends(array $dividends): string
    {
        $writer = Writer::fromString();
        $writer->insertOne(self::DIVIDEND_HEADER);

        foreach ($dividends as $dividend) {
            $writer->insertOne(array_map(CsvCell::safe(...), [
                $dividend->name,
                $dividend->countryCode,
                $dividend->currency,
                $dividend->date->format('Y-m-d'),
                (string) $dividend->grossAmount->value(),
                (string) $dividend->withheldTax->value(),
            ]));
        }

        return $writer->toString();
    }
}
