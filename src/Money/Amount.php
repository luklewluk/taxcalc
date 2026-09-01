<?php

declare(strict_types=1);

namespace App\Money;

use App\Exception\CurrencyMismatchException;
use App\Exception\InvalidCurrencyException;
use Stringable;

/**
 * A decimal value tagged with an ISO 4217 currency code.
 */
final readonly class Amount implements Stringable
{
    private function __construct(
        private Decimal $value,
        private string $currency,
    ) {
    }

    public static function of(string|int $value, string $currency): self
    {
        return new self(Decimal::of($value), self::assertCurrency($currency));
    }

    public static function fromDecimal(Decimal $value, string $currency): self
    {
        return new self($value, self::assertCurrency($currency));
    }

    public static function zero(string $currency): self
    {
        return new self(Decimal::zero(), self::assertCurrency($currency));
    }

    public static function sum(string $currency, self ...$amounts): self
    {
        $total = self::zero($currency);
        foreach ($amounts as $amount) {
            $total = $total->plus($amount);
        }

        return $total;
    }

    public function value(): Decimal
    {
        return $this->value;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->value->plus($other->value), $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->value->minus($other->value), $this->currency);
    }

    public function multipliedBy(Decimal $factor): self
    {
        return new self($this->value->multipliedBy($factor), $this->currency);
    }

    /**
     * The `$part / $whole` share of this amount, at this amount's own scale.
     *
     * Used to split a lot's cost across the sells that consume it. The ratio
     * stays exact until the single closing rounding, so the result never drifts
     * with the size of the amount; see {@see Decimal::multipliedByRatio()}.
     */
    public function proratedBy(Decimal $part, Decimal $whole): self
    {
        return new self(
            $this->value->multipliedByRatio($part, $whole, $this->value->scale()),
            $this->currency,
        );
    }

    public function percentage(Decimal $percent): self
    {
        return new self($this->value->percentage($percent), $this->currency);
    }

    public function negated(): self
    {
        return new self($this->value->negated(), $this->currency);
    }

    public function abs(): self
    {
        return new self($this->value->abs(), $this->currency);
    }

    public function toScale(int $scale): self
    {
        return new self($this->value->toScale($scale), $this->currency);
    }

    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->value->compareTo($other->value);
    }

    public function isNegative(): bool
    {
        return $this->value->isNegative();
    }

    public function isZero(): bool
    {
        return $this->value->isZero();
    }

    public function isPositive(): bool
    {
        return $this->value->isPositive();
    }

    public function __toString(): string
    {
        return $this->value.' '.$this->currency;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatchException::between($this->currency, $other->currency);
        }
    }

    private static function assertCurrency(string $currency): string
    {
        if (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            throw InvalidCurrencyException::notIso4217($currency);
        }

        return $currency;
    }
}
