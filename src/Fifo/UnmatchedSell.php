<?php

declare(strict_types=1);

namespace App\Fifo;

use App\Money\Decimal;
use DateTimeImmutable;

/**
 * The part of a sell that had no corresponding buy in the imported data.
 *
 * Typically means the opening transactions live in an earlier statement that
 * has not been uploaded. Reported as a warning rather than an exception so the
 * rest of the import stays usable.
 */
final readonly class UnmatchedSell
{
    public function __construct(
        public string $symbol,
        public DateTimeImmutable $date,
        public Decimal $quantity,
    ) {
    }
}
