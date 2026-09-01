<?php

declare(strict_types=1);

namespace App\Exception;

use InvalidArgumentException;

final class InvalidCurrencyException extends InvalidArgumentException
{
    public static function notIso4217(string $code): self
    {
        return new self(sprintf('"%s" is not a valid ISO 4217 currency code.', $code));
    }
}
