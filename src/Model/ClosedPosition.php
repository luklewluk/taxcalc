<?php

declare(strict_types=1);

namespace App\Model;

use App\Exception\InvalidRecordException;
use App\Fifo\InstrumentKind;
use App\Fifo\PositionDirection;
use App\Money\Amount;
use App\Money\Decimal;
use DateTimeImmutable;

/**
 * A normalized, fully closed position: one buy leg matched to one sell leg.
 * This is the common shape every importer produces and the only shape the tax
 * calculator understands.
 *
 * Przychód is always the sell leg and koszt the buy leg. For a stock or a bought
 * option the sell closes the position; for a written option (`Short`) the sell
 * opened it and the buy closes it - which moves the tax year and the date the
 * przychód is converted at to the buy leg, see {@see closeDate()}.
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
        public InstrumentKind $kind = InstrumentKind::Stock,
        public PositionDirection $direction = PositionDirection::Long,
    ) {
        // Invariants live here because this is the one type every importer
        // produces and every form submission is mapped into. A negative or zero
        // leg would otherwise turn into a plausible-looking tax figure.
        if (InstrumentKind::Option !== $kind) {
            if (PositionDirection::Short === $direction) {
                throw new InvalidRecordException(
                    'Krótka sprzedaż akcji nie jest obsługiwana - sprzedaż musi mieć pokrycie we wcześniejszym zakupie.',
                );
            }

            self::assertPositive('kwota zakupu', $buyAmount, $currency);
            self::assertPositive('kwota sprzedaży', $sellAmount, $currency);
        } else {
            // An option expires or is assigned at nothing, so its *closing* leg
            // may be zero - and then it cannot have cost a fee. The opening leg
            // is a premium that was really paid or received.
            $long = PositionDirection::Long === $direction;
            self::assertPositive($long ? 'kwota zakupu' : 'kwota sprzedaży', $long ? $buyAmount : $sellAmount, $currency);
            self::assertNotNegative($long ? 'kwota sprzedaży' : 'kwota zakupu', $long ? $sellAmount : $buyAmount, $currency);

            $closing = $long ? $sellAmount : $buyAmount;
            $closingFees = $long ? [$sellCommission, $sellAutoFx] : [$buyCommission, $buyAutoFx];
            foreach ($closingFees as $fee) {
                if ($closing->isZero() && null !== $fee && !$fee->isZero()) {
                    throw new InvalidRecordException(sprintf(
                        'Zamknięcie opcji kwotą 0 (wygaśnięcie, przydział) nie może mieć opłaty %s %s.',
                        (string) $fee->value(),
                        $fee->currency(),
                    ));
                }
            }
        }

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

    private static function assertNotNegative(string $field, Amount $amount, string $currency): void
    {
        if ($amount->currency() !== $currency) {
            throw InvalidRecordException::currencyMismatch($field, $currency, $amount->currency());
        }

        if ($amount->isNegative()) {
            throw InvalidRecordException::amountMustNotBeNegative($field, $amount);
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

    public function isOption(): bool
    {
        return InstrumentKind::Option === $this->kind;
    }

    public function isShort(): bool
    {
        return PositionDirection::Short === $this->direction;
    }

    public function openDate(): DateTimeImmutable
    {
        return $this->isShort() ? $this->sellDate : $this->buyDate;
    }

    /**
     * The day the position closed: the sale, or for a written option the buy
     * that closed it (buy-to-close, expiry, assignment).
     */
    public function closeDate(): DateTimeImmutable
    {
        return $this->isShort() ? $this->buyDate : $this->sellDate;
    }

    /**
     * The day the przychód arises, whose preceding business day's NBP rate
     * converts it (art. 11a ust. 1). An option premium is przychód only when
     * the position closes (art. 17 ust. 1b), so for a written option that is
     * the closing buy, not the day the premium was received.
     */
    public function revenueDate(): DateTimeImmutable
    {
        return $this->closeDate();
    }

    /**
     * Every day whose NBP rate this position needs: the cost on the buy, the
     * disposal fee on the sell, and the przychód on {@see revenueDate()}.
     *
     * @return list<DateTimeImmutable>
     */
    public function conversionDates(): array
    {
        $dates = [];
        foreach ([$this->buyDate, $this->sellDate, $this->revenueDate()] as $date) {
            $dates[$date->format('Y-m-d')] ??= $date;
        }

        return array_values($dates);
    }

    /**
     * The tax year a position belongs to is the year the income was realised,
     * i.e. the year it *closed* - the sale for a stock. The opening leg may
     * well be from an earlier year.
     */
    public function taxYear(): int
    {
        return (int) $this->closeDate()->format('Y');
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
        // Appended only for options, so every stock position keeps the identity
        // a workbench posted before options existed.
        $kind = $this->isOption() ? '|'.$this->kind->value.'|'.$this->direction->value : '';

        if (null !== $this->lineageKey) {
            return hash('sha256', 'position-lineage|'.$this->lineageKey.$kind);
        }

        return hash('sha256', $kind.implode('|', [
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
