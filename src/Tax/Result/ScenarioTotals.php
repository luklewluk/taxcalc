<?php

declare(strict_types=1);

namespace App\Tax\Result;

use App\Money\Amount;
use App\Tax\CreditMethod;

/**
 * Declared totals for one credit scenario, rounded at the reporting boundary.
 */
final readonly class ScenarioTotals
{
    public function __construct(
        public CreditMethod $method,
        public Amount $creditableTax,
        public Amount $taxDue,
        public Amount $taxDueRoundedToZloty,
    ) {
    }

    public function label(): string
    {
        return $this->method->label();
    }
}
