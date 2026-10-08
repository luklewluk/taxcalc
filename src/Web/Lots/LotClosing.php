<?php

declare(strict_types=1);

namespace App\Web\Lots;

use App\Money\Decimal;

/**
 * A closing on the right, with the lots it consumed.
 */
final readonly class LotClosing
{
    /**
     * @param array<string, string> $row
     * @param list<LotLink>         $lots
     * @param list<string>          $problems what FIFO or the named lots could not do, in their own words
     */
    public function __construct(
        public string $label,
        public string $tradeId,
        public array $row,
        public ?Decimal $quantity,
        public bool $specific,
        public array $lots,
        public ?Decimal $unmatched,
        public array $problems,
        public bool $editable,
    ) {
    }
}
