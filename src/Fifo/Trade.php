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
        /** Whether externalId was reported by the broker, rather than synthesized. */
        public bool $externalIdReported = false,
        /** Broker-reported execution price, audit-only and independent from the settled cash (Total). */
        public ?Amount $unitPrice = null,
        /** Execution venue, likewise retained only for safe correction recognition. */
        public string $executionVenue = '',
        /** Broker/account pool. FIFO queues from different brokers never meet. */
        public string $broker = '',
        /**
         * Explicit broker fees, when the source reports them separately.
         *
         * Never added to `$grossAmount` - the settled cash already includes them.
         * They are not merely audit data either: on the *sell* leg the settled
         * cash has these taken out of it, and Polish rules declare the gross
         * amount due with the fee counted as a cost of disposal instead. See
         * {@see \App\Model\ClosedPosition::disposalFee()}.
         */
        public ?Amount $commission = null,
        public ?Amount $autoFx = null,
        /** Stable browser-form identity, independent from later manual edits. */
        public string $stableId = '',
        /** Optional explicit FIFO pool key when the display symbol is ambiguous. */
        public string $fifoPool = '',
        public InstrumentKind $kind = InstrumentKind::Stock,
        /** Required for an option, ignored for a stock; see {@see PositionEffect}. */
        public ?PositionEffect $effect = null,
    ) {
    }

    public function isOption(): bool
    {
        return InstrumentKind::Option === $this->kind;
    }

    public function isBuy(): bool
    {
        return $this->quantity->isPositive();
    }

    public function isSell(): bool
    {
        return $this->quantity->isNegative();
    }

    public function id(): string
    {
        if ('' !== $this->stableId) {
            return $this->stableId;
        }

        $fields = [
            'trade', $this->broker, $this->fifoPool, $this->symbol, $this->date->format('Y-m-d H:i:s'),
            (string) $this->quantity, (string) $this->grossAmount->value(),
            $this->grossAmount->currency(), $this->externalId ?? '', (string) $this->fillOrdinal,
        ];

        // Appended only for options, so every stock trade keeps the identity a
        // workbench posted before options existed (stable IDs, tombstones).
        if ($this->isOption()) {
            $fields[] = $this->kind->value;
            $fields[] = $this->effect->value ?? '';
        }

        return hash('sha256', implode('|', $fields));
    }
}
