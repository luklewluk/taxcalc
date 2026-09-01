<?php

declare(strict_types=1);

namespace App\Exception;

use InvalidArgumentException;

final class InvalidNumberException extends InvalidArgumentException
{
    public static function notADecimal(string $value): self
    {
        return new self(sprintf('"%s" is not a valid decimal number.', $value));
    }

    public static function divisionByZero(): self
    {
        return new self('Division by zero.');
    }

    public static function negativeScale(int $scale): self
    {
        return new self(sprintf('Decimal scale must not be negative, got %d.', $scale));
    }
}
