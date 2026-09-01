<?php

declare(strict_types=1);

namespace App\Fifo;

final readonly class FifoResult
{
    /**
     * @param list<FifoMatch>    $matches
     * @param list<UnmatchedSell> $unmatchedSells
     */
    public function __construct(
        public array $matches,
        public array $unmatchedSells,
    ) {
    }
}
