<?php

declare(strict_types=1);

namespace App\Tax\Result;

use App\Money\Amount;

/**
 * Credit and remaining tax under one of the two competing readings.
 *
 * Amounts are kept at full decimal precision; rounding happens once, at the
 * declared total or at the moment a figure is rendered.
 */
final readonly class DividendCredit
{
    public function __construct(
        public Amount $creditableTax,
        public Amount $taxDue,
    ) {
    }
}
