<?php

declare(strict_types=1);

namespace App\Exception;

use DateTimeInterface;
use RuntimeException;
use Throwable;

final class ExchangeRateUnavailableException extends RuntimeException
{
    public static function forCurrency(
        string $currency,
        DateTimeInterface $date,
        ?Throwable $previous = null,
    ): self {
        return new self(
            sprintf(
                'No NBP exchange rate available for %s on or before %s.',
                $currency,
                $date->format('Y-m-d'),
            ),
            0,
            $previous,
        );
    }
}
