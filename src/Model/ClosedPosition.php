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
        /**
         * Broker fees, per leg, in the position's own currency, `null` when the
         * source did not report them (a reported `0` is data, not absence).
         *
         * The two sides are *not* symmetric, and this is the whole reason they
         * exist as fields. `buyAmount` is the cash that left the account, so the
         * buy fee is already inside the acquisition cost. `sellAmount` is the
         * cash that entered it, so the sell fee has already been taken *out* of
         * the proceeds - and Polish rules want the declared przychód to be the
         * gross amount due with that fee counted as a koszt odpłatnego zbycia.
         * {@see disposalFee()} is what the tax layer adds back.
         */
        public ?Amount $buyCommission = null,
        public ?Amount $sellCommission = null,
        public ?Amount $buyAutoFx = null,
        public ?Amount $sellAutoFx = null,
        public string $broker = '',
        public string $symbol = '',
        public string $buyTradeId = '',
        public string $sellTradeId = '',
        /** Audit-only execution prices. Tax amounts remain buyAmount/sellAmount. */
        public ?Amount $buyUnitPrice = null,
        public ?Amount $sellUnitPrice = null,
    ) {
        // Invariants live here because this is the one type every importer
        // produces and every form submission is mapped into. A negative or zero
        // leg would otherwise turn into a plausible-looking tax figure.
        self::assertPositive('kwota zakupu', $buyAmount, $currency);
        self::assertPositive('kwota sprzedaży', $sellAmount, $currency);

        if (null !== $quantity && !$quantity->isPositive()) {
            throw InvalidRecordException::quantityMustBePositive((string) $quantity);
        }

        // Fees feed the declared przychód and koszt, so they get the same
        // treatment as the legs. Refusing here rather than in the tax layer is
        // deliberate: Amount::plus() would throw CurrencyMismatchException,
        // a LogicException nothing on the report path catches, while every
        // caller of this constructor already turns InvalidRecordException into
        // a message on the offending row.
        self::assertFee('prowizja zakupu', $buyCommission, $currency);
        self::assertFee('prowizja sprzedaży', $sellCommission, $currency);
        self::assertFee('AutoFX zakupu', $buyAutoFx, $currency);
        self::assertFee('AutoFX sprzedaży', $sellAutoFx, $currency);
    }

    /**
     * The cost of *disposing* of this position: the fees the broker took out of
     * the settled proceeds, summed in the position's currency.
     *
     * `null` means no fee was reported on either side, i.e. the split between
     * przychód and koszt cannot be made for this position - the settled cash is
     * all the source gave us. A reported `0` is not absence, and one reported
     * fee next to one absent one sums to the reported one.
     *
     * Note the difference from {@see \App\Import\CsvImportService::sumOptional()},
     * which is deliberately null-annihilating: there, a partial fee would
     * misdescribe an aggregated order, so it is dropped. Here, dropping it would
     * silently understate a tax figure for the commonest DEGIRO export, whose
     * files carry a transaction fee and no AutoFX column at all.
     */
    public function disposalFee(): ?Amount
    {
        $reported = array_values(array_filter(
            [$this->sellCommission, $this->sellAutoFx],
            static fn (?Amount $fee): bool => null !== $fee,
        ));

        return [] === $reported ? null : Amount::sum($this->currency, ...$reported);
    }

    private static function assertFee(string $field, ?Amount $fee, string $currency): void
    {
        if (null === $fee) {
            return;
        }

        if ($fee->currency() !== $currency) {
            throw InvalidRecordException::currencyMismatch($field, $currency, $fee->currency());
        }

        if ($fee->isNegative()) {
            throw InvalidRecordException::amountMustNotBeNegative($field, $fee);
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
