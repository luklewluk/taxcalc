<?php

declare(strict_types=1);

namespace App\Money;

use App\Exception\InvalidNumberException;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Stringable;

/**
 * Immutable arbitrary-precision decimal.
 *
 * Every monetary and quantity value in the domain flows through this class so
 * that no binary float ever touches a tax figure. Rounding is always explicit:
 * there is no implicit scale, and division requires a caller-supplied scale.
 */
final readonly class Decimal implements Stringable
{
    private function __construct(private BigDecimal $value)
    {
    }

    public static function of(string|int $value): self
    {
        if (is_int($value)) {
            return new self(BigDecimal::of($value));
        }

        $trimmed = trim($value);

        // Brick accepts exponent notation, which we want, but it also accepts
        // nothing else - guard against locale separators and stray characters
        // early so the failure mode is a domain exception, not a math error.
        if (!preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/', $trimmed)) {
            throw InvalidNumberException::notADecimal($value);
        }

        try {
            return new self(BigDecimal::of($trimmed));
        } catch (MathException) {
            throw InvalidNumberException::notADecimal($value);
        }
    }

    public static function zero(): self
    {
        return new self(BigDecimal::zero());
    }

    public function plus(self $other): self
    {
        return new self($this->value->plus($other->value));
    }

    public function minus(self $other): self
    {
        return new self($this->value->minus($other->value));
    }

    public function multipliedBy(self $other): self
    {
        return new self($this->value->multipliedBy($other->value));
    }

    public function dividedBy(self $other, int $scale): self
    {
        if ($other->isZero()) {
            throw InvalidNumberException::divisionByZero();
        }

        return new self($this->value->dividedBy($other->value, self::assertScale($scale), RoundingMode::HalfUp));
    }

    /**
     * This value times `$numerator / $denominator`, rounded once to `$scale`.
     *
     * The ratio is kept exact until that final rounding, which is what makes
     * this different from dividing first and multiplying afterwards. A share
     * such as 1/3 has no finite decimal form, so any intermediate scale loses
     * money in proportion to the amount: at six decimal places, a third of a
     * billion comes out 333.33 short. Prorating a cost basis over partially
     * consumed lots is exactly that computation.
     */
    public function multipliedByRatio(self $numerator, self $denominator, int $scale): self
    {
        if ($denominator->isZero()) {
            throw InvalidNumberException::divisionByZero();
        }

        return new self(
            $this->value
                ->toBigRational()
                ->multipliedBy($numerator->value->toBigRational())
                ->dividedBy($denominator->value->toBigRational())
                ->toScale(self::assertScale($scale), RoundingMode::HalfUp),
        );
    }

    /**
     * `$percent` percent of this value, e.g. `Decimal::of('19')` for 19%.
     */
    public function percentage(self $percent): self
    {
        return $this->multipliedBy($percent)->dividedBy(self::of(100), $this->scale() + $percent->scale() + 2);
    }

    public function negated(): self
    {
        return new self($this->value->negated());
    }

    public function abs(): self
    {
        return new self($this->value->abs());
    }

    public function min(self $other): self
    {
        return $this->compareTo($other) <= 0 ? $this : $other;
    }

    public function max(self $other): self
    {
        return $this->compareTo($other) >= 0 ? $this : $other;
    }

    /**
     * @return int -1, 0 or 1
     */
    public function compareTo(self $other): int
    {
        return $this->value->compareTo($other->value);
    }

    public function isNegative(): bool
    {
        return $this->value->isNegative();
    }

    public function isPositive(): bool
    {
        return $this->value->isPositive();
    }

    public function isZero(): bool
    {
        return $this->value->isZero();
    }

    public function toScale(int $scale): self
    {
        return new self($this->value->toScale(self::assertScale($scale), RoundingMode::HalfUp));
    }

    public function scale(): int
    {
        return $this->value->getScale();
    }

    /**
     * A negative scale is meaningless here and would silently round to tens or
     * hundreds, so it is rejected rather than accepted.
     *
     * @return int<0, max>
     */
    private static function assertScale(int $scale): int
    {
        if ($scale < 0) {
            throw InvalidNumberException::negativeScale($scale);
        }

        return $scale;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
