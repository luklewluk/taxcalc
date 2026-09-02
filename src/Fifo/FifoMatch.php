<?php

declare(strict_types=1);

namespace App\Fifo;

use App\Money\Amount;
use App\Money\Decimal;
use DateTimeImmutable;

/**
 * One buy lot matched against one sell, i.e. exactly one closed position.
 */
final readonly class FifoMatch
{
    public function __construct(
        public string $symbol,
        public DateTimeImmutable $buyDate,
        public DateTimeImmutable $sellDate,
        public Decimal $quantity,
        public Amount $buyCost,
        public Amount $sellProceeds,
        public ?string $buyExternalId = null,
        public ?string $sellExternalId = null,
        public string $buySource = '',
        public string $sellSource = '',
        /**
         * Position of this match in the matcher's output, counted from 1.
         *
         * Two identical lots closed by one sell produce two matches that agree
         * on every other field, including the broker's IDs when those identify
         * an *order* rather than a fill. Without an ordinal they would collapse
         * into one closed position and understate the tax due.
         */
        public int $sequence = 0,
        public ?InstrumentDetails $buyInstrument = null,
        public ?InstrumentDetails $sellInstrument = null,
        public ?Amount $buyCommission = null,
        public ?Amount $sellCommission = null,
        public ?Amount $buyAutoFx = null,
        public ?Amount $sellAutoFx = null,
        public string $broker = '',
        public string $buyTradeId = '',
        public string $sellTradeId = '',
        /** Audit-only broker execution prices; FIFO amounts still come from Total/NetCash. */
        public ?Amount $buyUnitPrice = null,
        public ?Amount $sellUnitPrice = null,
    ) {
    }

    /**
     * The instrument details to display for this position.
     *
     * The sell leg wins: it is the later of the two, so after a rename it
     * carries the name the instrument goes by now.
     */
    public function instrument(): ?InstrumentDetails
    {
        return $this->sellInstrument ?? $this->buyInstrument;
    }

    /**
     * Stable identity of this match in terms of the *raw broker transactions*
     * that produced it.
     *
     * Two economically identical fills settled on the same day are different
     * trades, and content alone cannot tell them apart. The broker's transaction
     * IDs can, so they are carried through matching and used for de-duplication.
     *
     * Returns null when the source did not provide both IDs, in which case
     * callers fall back to a content fingerprint.
     */
    public function lineageKey(): ?string
    {
        if (null === $this->buyExternalId || null === $this->sellExternalId) {
            return null;
        }

        return implode('|', [
            $this->broker,
            $this->symbol,
            $this->buyExternalId,
            $this->sellExternalId,
            (string) $this->quantity,
            (string) $this->sequence,
        ]);
    }
}
