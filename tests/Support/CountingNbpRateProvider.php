<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\CurrencyRate\NbpRate;
use App\CurrencyRate\NbpRateProviderInterface;
use DateTimeImmutable;

/** Counts lookups per currency, so a test can see which ones were never made. */
final class CountingNbpRateProvider implements NbpRateProviderInterface
{
    /** @var array<string, int> */
    public array $calls = [];

    public function __construct(private readonly NbpRateProviderInterface $inner)
    {
    }

    public function rateForPreviousBusinessDay(string $currency, DateTimeImmutable $transactionDate): NbpRate
    {
        $this->calls[$currency] = ($this->calls[$currency] ?? 0) + 1;

        return $this->inner->rateForPreviousBusinessDay($currency, $transactionDate);
    }
}
