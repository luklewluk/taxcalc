<?php

declare(strict_types=1);

namespace App\Import\Importer;

use App\Fifo\Trade;
use App\Import\ImportMessage;

/**
 * Raw broker transactions read from one file, before any FIFO matching.
 */
final readonly class TradeExtraction
{
    /**
     * @param list<Trade>         $trades
     * @param list<ImportMessage> $messages
     */
    public function __construct(
        public array $trades = [],
        public array $messages = [],
    ) {
    }
}
