<?php

declare(strict_types=1);

namespace App\Web\Ledger;

use App\Fifo\PositionDirection;
use App\Money\Decimal;

/** One FIFO queue - one paper at one broker - with its trades in time order. */
final readonly class InstrumentGroup
{
    /**
     * @param list<string>      $countries distinct countries the rows carry
     * @param list<LedgerEntry> $entries
     */
    public function __construct(
        public string $key,
        public string $broker,
        public string $pool,
        public string $symbol,
        public string $title,
        public bool $isOption,
        /** Stocks and options in one queue - FIFO refuses it, so the rows stay together. */
        public bool $mixedKinds,
        public array $countries,
        public int $blankCountries,
        /** The country group of the blank rows, so the header can point at its fix. */
        public string $countryGroupId,
        /** `null` without a FIFO run. */
        public ?Decimal $openQuantity,
        public ?PositionDirection $direction,
        public array $entries,
    ) {
    }

    public function isClosed(): bool
    {
        return null !== $this->openQuantity && $this->openQuantity->isZero();
    }
}
