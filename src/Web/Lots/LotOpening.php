<?php

declare(strict_types=1);

namespace App\Web\Lots;

use App\Money\Decimal;

/**
 * A lot on the left: when it opened, how big it is, what closed it, what is left.
 */
final readonly class LotOpening
{
    /**
     * @param array<string, string> $row
     * @param list<LotLink>         $closedBy
     */
    public function __construct(
        public string $label,
        public string $tradeId,
        public array $row,
        public ?Decimal $quantity,
        public Decimal $remaining,
        public array $closedBy,
    ) {
    }
}
