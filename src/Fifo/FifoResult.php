<?php

declare(strict_types=1);

namespace App\Fifo;

final readonly class FifoResult
{
    /**
     * @param list<FifoMatch>     $matches
     * @param list<UnmatchedSell> $unmatchedSells
     * @param list<FifoViolation> $violations     option queues only
     * @param list<OpenPosition>  $openPositions  what every queue still holds, oldest first
     */
    public function __construct(
        public array $matches,
        public array $unmatchedSells,
        public array $violations = [],
        public array $openPositions = [],
    ) {
    }
}
