<?php

declare(strict_types=1);

namespace App\Web\Lots;

use App\Fifo\LotAssignments;

/**
 * The hidden lot field, read: either the assignments, or why it could not be.
 */
final readonly class LotAssignmentsDecoded
{
    public function __construct(
        public LotAssignments $assignments,
        public bool $valid,
        public ?string $message = null,
    ) {
    }
}
