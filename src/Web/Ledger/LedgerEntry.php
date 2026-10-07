<?php

declare(strict_types=1);

namespace App\Web\Ledger;

use App\Web\Diagnostic;

/** One trade row of the ledger, at the index it is posted under. */
final readonly class LedgerEntry
{
    /**
     * @param array<string, string> $row
     * @param list<Diagnostic>      $messages
     */
    public function __construct(
        public int $index,
        public string $id,
        public array $row,
        public bool $isOption,
        public ?TradeDetail $detail,
        public array $messages,
        /** Rendered with its editor open: a row the server could not accept. */
        public bool $editorOpen,
    ) {
    }
}
