<?php

declare(strict_types=1);

namespace App\Web\Lots;

/**
 * One FIFO queue: openings on the left, closings on the right.
 */
final readonly class LotQueue
{
    /**
     * @param list<LotOpening> $openings
     * @param list<LotClosing> $closings
     */
    public function __construct(
        public string $key,
        public string $broker,
        public string $title,
        public bool $isOption,
        public bool $mixedKinds,
        public bool $expanded,
        public array $openings,
        public array $closings,
    ) {
    }
}
