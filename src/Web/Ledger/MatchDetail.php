<?php

declare(strict_types=1);

namespace App\Web\Ledger;

use App\Fifo\FifoMatch;
use App\Model\ClosedPosition;
use App\Money\Amount;
use App\Tax\Result\CalculatedPosition;

/**
 * One FIFO match seen from one of its two trades: the closing trade sees the
 * lot it closed, the opening trade sees the trade that closed it.
 */
final readonly class MatchDetail
{
    /** No PLN figures: the NBP rate could not be fetched. */
    public const string RATE = 'rate';
    /** No PLN figures yet: the time for rate lookups ran out on this render. */
    public const string BUDGET = 'budget';
    /** No PLN figures: the match could not become a position (see the attention panel). */
    public const string POSITION = 'position';

    /**
     * @param array<string, string>|null $counterpart the other trade's form row, when it is in the form
     */
    public function __construct(
        public FifoMatch $match,
        public ?ClosedPosition $position,
        public string $counterpartId,
        public ?array $counterpart,
        public ?CalculatedPosition $calculated,
        public ?string $unavailable,
        /** 19% of a positive income, exact - informational, see {@see TradeDetail}. */
        public ?Amount $informationalTax,
    ) {
    }
}
