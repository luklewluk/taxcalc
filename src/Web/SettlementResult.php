<?php

declare(strict_types=1);

namespace App\Web;

use App\Fifo\FifoMatch;
use App\Model\ClosedPosition;

final readonly class SettlementResult
{
    /**
     * @param list<ClosedPosition> $positions
     * @param list<FifoMatch>      $matches
     * @param list<string>         $errors
     * @param list<Diagnostic>     $diagnostics
     */
    public function __construct(
        public array $positions,
        public array $matches,
        public array $errors,
        public array $diagnostics = [],
    ) {
    }
}
