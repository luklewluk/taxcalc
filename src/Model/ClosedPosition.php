<?php

declare(strict_types=1);

namespace App\Model;

use App\Exception\InvalidRecordException;
use App\Money\Amount;
use App\Money\Decimal;
use DateTimeImmutable;

/**
 * A normalized, fully closed stock position: one buy leg matched to one sell
 * leg. This is the common shape every importer produces and the only shape the
 * tax calculator understands.
 */
final readonly class ClosedPosition
{
    public function __construct(
        public string $name,
        public string $countryCode,
        public string $currency,
        public DateTimeImmutable $buyDate,
        public Amount $buyAmount,
        public DateTimeImmutable $sellDate,
        public Amount $sellAmount,
        public ?Decimal $quantity,
        public string $source,
        /**
         * Identity of the raw broker transactions behind this position, when the
         * source provided them. See {@see fingerprint()}.
         */
        public ?string $lineageKey = null,
    ) {
        // Invariants live here because this is the one type every importer
        // produces and every form submission is mapped into. A negative or zero
        // leg would otherwise turn into a plausible-looking tax figure.
        self::assertPositive('kwota zakupu', $buyAmount, $currency);
        self::assertPositive('kwota sprzedaży', $sellAmount, $currency);

        if (null !== $quantity && !$quantity->isPositive()) {
            throw InvalidRecordException::quantityMustBePositive((string) $quantity);
        }
    }

    private static function assertPositive(string $field, Amount $amount, string $currency): void
    {
        if ($amount->currency() !== $currency) {
            throw InvalidRecordException::currencyMismatch($field, $currency, $amount->currency());
        }

        if (!$amount->isPositive()) {
            throw InvalidRecordException::amountMustBePositive($field, $amount);
        }
    }

    /**
     * The tax year a position belongs to is the year the income was realised,
     * i.e. the year of the *sale*. The buy may well be from an earlier year.
     */
    public function taxYear(): int
    {
        return (int) $this->sellDate->format('Y');
    }

    /**
     * Stable identity used to drop duplicates when the same statement is
     * uploaded twice. Derived purely from content or from the broker's own
     * transaction IDs, so it stays the same across requests without anything
     * being persisted.
     *
     * When a lineage key is available it wins, because content alone cannot
     * distinguish two genuine identical fills of the same instrument on the same
     * day - collapsing those would silently understate the tax due.
     */
    public function fingerprint(): string
    {
        if (null !== $this->lineageKey) {
            return hash('sha256', 'position-lineage|'.$this->lineageKey);
        }

        return hash('sha256', implode('|', [
            'position',
            $this->name,
            $this->countryCode,
            $this->currency,
            $this->buyDate->format('Y-m-d'),
            (string) $this->buyAmount->value(),
            $this->sellDate->format('Y-m-d'),
            (string) $this->sellAmount->value(),
            (string) $this->quantity,
        ]));
    }
}
