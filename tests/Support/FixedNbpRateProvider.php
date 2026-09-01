<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\CurrencyRate\NbpRate;
use App\CurrencyRate\NbpRateProviderInterface;
use App\Exception\ExchangeRateUnavailableException;
use App\Money\Decimal;
use DateTimeImmutable;

/**
 * Deterministic, offline replacement for the real NBP provider.
 *
 * Wired in `config/services.yaml` under `when@test` so that no test - unit or
 * functional - can ever reach the network.
 */
final class FixedNbpRateProvider implements NbpRateProviderInterface
{
    /**
     * @var array<string, string>
     */
    private const array RATES = [
        'USD' => '4.0000',
        'EUR' => '4.3000',
        'GBP' => '5.0000',
        'CHF' => '4.5000',
        'CAD' => '3.0000',
    ];

    public function rateForPreviousBusinessDay(string $currency, DateTimeImmutable $transactionDate): NbpRate
    {
        $code = strtoupper($currency);
        if (!isset(self::RATES[$code])) {
            throw ExchangeRateUnavailableException::forCurrency($currency, $transactionDate);
        }

        return new NbpRate(
            $code,
            Decimal::of(self::RATES[$code]),
            $transactionDate->modify('-1 day'),
            'TEST/A/NBP',
        );
    }
}
