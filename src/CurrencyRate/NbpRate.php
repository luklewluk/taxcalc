<?php

declare(strict_types=1);

namespace App\CurrencyRate;

use App\Money\Decimal;
use DateTimeImmutable;

/**
 * A single average (table A) NBP quotation.
 */
final readonly class NbpRate
{
    public function __construct(
        public string $currency,
        public Decimal $rate,
        public DateTimeImmutable $date,
        public ?string $table,
    ) {
    }
}
