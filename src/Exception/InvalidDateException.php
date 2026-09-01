<?php

declare(strict_types=1);

namespace App\Exception;

use InvalidArgumentException;

final class InvalidDateException extends InvalidArgumentException
{
    public static function unrecognised(string $value): self
    {
        return new self(sprintf('"%s" is not a recognised date.', $value));
    }

    public static function unrecognisedTime(string $value): self
    {
        return new self(sprintf('"%s" is not a recognised time of day.', $value));
    }
}
