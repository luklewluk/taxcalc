<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\CurrencyRate\NbpRate;
use App\CurrencyRate\NbpRateProviderInterface;
use App\Exception\ExchangeRateUnavailableException;
use DateTimeImmutable;

/**
 * The offline provider, except that the listed transaction days have no rate -
 * so a test can prove which days a calculation asks for.
 */
final readonly class DateGatedNbpRateProvider implements NbpRateProviderInterface
{
    /**
     * @param list<string> $missingDays Y-m-d transaction dates without a rate
     */
    public function __construct(private array $missingDays)
    {
    }

    public function rateForPreviousBusinessDay(string $currency, DateTimeImmutable $transactionDate): NbpRate
    {
        if (in_array($transactionDate->format('Y-m-d'), $this->missingDays, true)) {
            throw ExchangeRateUnavailableException::forCurrency($currency, $transactionDate);
        }

        return (new FixedNbpRateProvider())->rateForPreviousBusinessDay($currency, $transactionDate);
    }
}
