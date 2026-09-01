<?php

declare(strict_types=1);

namespace App\Import\Importer;

use App\Fifo\Trade;
use App\Import\CsvSource;
use App\Import\ImportResult;
use App\Import\TradeIdScope;

/**
 * An importer whose files hold raw buy/sell transactions rather than finished
 * positions.
 *
 * Matching is split out from reading so that the import service can pool the
 * trades of *every* uploaded file and run FIFO once. Brokers export one
 * statement per year, so the buy leg of a position settled this year is usually
 * in last year's file; matching per file would lose it.
 *
 * Pooling stops at the broker, though: each implementation gets its own batch.
 * Transaction identifiers are only unique within the broker that issued them,
 * and one broker's statements say nothing about another's positions.
 */
interface TradeSourceImporterInterface extends ImporterInterface
{
    public function extractTrades(CsvSource $source): TradeExtraction;

    /**
     * @param list<Trade> $trades from any number of files of *this* format, in
     *                            any order
     */
    public function matchTrades(array $trades): ImportResult;

    /**
     * What {@see Trade::$externalId} identifies in this format, which decides
     * how duplicates and conflicts are told apart.
     */
    public function tradeIdScope(): TradeIdScope;

    /**
     * Name of the identifier column as the broker prints it, so a conflict
     * message points at something the user can find in their own file.
     */
    public function tradeIdLabel(): string;
}
