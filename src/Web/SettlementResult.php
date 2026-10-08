<?php

declare(strict_types=1);

namespace App\Web;

use App\Fifo\FifoMatch;
use App\Fifo\FifoViolation;
use App\Fifo\OpenPosition;
use App\Fifo\UnmatchedSell;
use App\Model\ClosedPosition;

final readonly class SettlementResult
{
    /**
     * @param list<ClosedPosition>       $positions      one per usable match
     * @param list<FifoMatch>            $matches
     * @param list<string>               $errors
     * @param list<Diagnostic>           $diagnostics
     * @param array<int, ClosedPosition> $matchPositions the position each match became, keyed by its index
     *                                                   in `$matches`; a match that could not become one
     *                                                   (currency change, invalid record) has no entry
     * @param list<OpenPosition>         $openPositions
     * @param list<UnmatchedSell>        $unmatchedSells
     * @param list<FifoViolation>        $violations
     */
    public function __construct(
        public array $positions,
        public array $matches,
        public array $errors,
        public array $diagnostics = [],
        public array $matchPositions = [],
        public array $openPositions = [],
        public array $unmatchedSells = [],
        public array $violations = [],
    ) {
    }
}
