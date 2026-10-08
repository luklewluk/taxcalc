<?php

declare(strict_types=1);

namespace App\Web\Lots;

/**
 * The FIFO tab's two-pane view: per queue, the lots that opened positions and
 * the closings each one fed - every year, not just the one being settled.
 */
final readonly class LotBoard
{
    /**
     * @param list<LotQueue> $queues
     */
    public function __construct(
        public array $queues,
        /** False while a trade row is invalid: FIFO has not run, so nothing links yet. */
        public bool $available,
        public bool $fieldValid,
        public bool $hasSpecific,
        public ?LotEditor $editor,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->queues;
    }
}
