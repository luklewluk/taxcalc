<?php

declare(strict_types=1);

namespace App\Web\Ledger;

use App\Fifo\PositionDirection;
use App\Money\Amount;
use App\Money\Decimal;

/**
 * Everything FIFO derived from one trade, for every year.
 *
 * `$closes` are the lots this trade closed - a sale of shares, or the buy that
 * closes a written option - and only such a trade has a tax year and an
 * income. `$closedBy` are the trades that later closed what this one opened.
 *
 * The 19% is informational: the return taxes the income of the whole year,
 * rounded once, and a loss elsewhere lowers it.
 */
final readonly class TradeDetail
{
    /**
     * @param list<MatchDetail> $closes
     * @param list<MatchDetail> $closedBy
     * @param list<string>      $notices  what FIFO could not match, in its own words
     */
    public function __construct(
        public array $closes,
        public array $closedBy,
        public ?Decimal $openQuantity,
        public ?PositionDirection $openDirection,
        public ?Decimal $unmatchedQuantity,
        public array $notices,
        public ?int $taxYear,
        /** `null` while any closed lot has no PLN figures. */
        public ?Amount $income,
        public ?Amount $informationalTax,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->closes && [] === $this->closedBy && null === $this->openQuantity
            && null === $this->unmatchedQuantity && [] === $this->notices;
    }
}
