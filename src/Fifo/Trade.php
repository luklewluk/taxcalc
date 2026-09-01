<?php

declare(strict_types=1);

namespace App\Fifo;

use App\Money\Amount;
use App\Money\Decimal;
use DateTimeImmutable;

/**
 * A single raw broker transaction: positive quantity is a buy, negative a sell.
 *
 * `$grossAmount` is always the absolute total cash of the trade (including
 * commission when the source reports net cash), never a per-share price.
 */
final readonly class Trade
{
    public function __construct(
        public string $symbol,
        public DateTimeImmutable $date,
        public Decimal $quantity,
        public Amount $grossAmount,
        public ?string $externalId = null,
        /**
         * Label of the file this trade came from. Matching runs across every
         * uploaded file at once, so a closed position can legitimately have its
         * buy leg in one statement and its sell leg in another.
         */
        public string $source = '',
        /**
         * Display name and country of the instrument, when the source carries
         * more than the matching key. Optional: exports whose symbol is already
         * the name the user knows leave it unset.
         */
        public ?InstrumentDetails $instrument = null,
        /**
         * Which occurrence of this exact row it is *within the file it was read
         * from*, counted from 1.
         *
         * Two economically identical fills are two trades, and only their
         * position inside one export tells them apart. Counting them while the
         * file is being read is what makes de-duplication independent of the
         * file's name: the same export re-uploaded, renamed or not, produces the
         * same ordinals, while two genuine identical rows in one export get 1
         * and 2 and both survive.
         */
        public int $fillOrdinal = 1,
    ) {
    }

    public function isBuy(): bool
    {
        return $this->quantity->isPositive();
    }

    public function isSell(): bool
    {
        return $this->quantity->isNegative();
    }
}
