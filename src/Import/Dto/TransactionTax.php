<?php

declare(strict_types=1);

namespace App\Import\Dto;

use App\Money\Amount;
use DateTimeImmutable;

/**
 * A tax charged on a purchase - the French financial transaction tax above
 * all - as the account statement books it, apart from the trade it belongs to.
 * {@see \App\Import\TransactionTaxApplier} adds it to that purchase's cost.
 */
final readonly class TransactionTax
{
    public function __construct(
        public string $broker,
        public string $isin,
        /** The booking day; the purchase is that day or a few days before. */
        public DateTimeImmutable $date,
        /** What was charged, positive. */
        public Amount $amount,
        public string $description,
        public string $source,
        public int $line,
        /** Content plus occurrence, so overlapping statements count it once. */
        public string $id,
    ) {
    }
}
