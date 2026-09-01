<?php

declare(strict_types=1);

namespace App\Exception;

use LogicException;

final class CurrencyMismatchException extends LogicException
{
    public static function between(string $left, string $right): self
    {
        return new self(sprintf('Cannot combine amounts in %s and %s.', $left, $right));
    }
}
