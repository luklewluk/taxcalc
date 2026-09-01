<?php

declare(strict_types=1);

namespace App\CurrencyRate;

use App\Exception\ExchangeRateUnavailableException;
use App\Exception\InvalidNumberException;
use App\Money\Decimal;
use DateTimeImmutable;
use Symfony\Component\HttpClient\Exception\JsonException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads average (table A) rates from the public NBP Web API.
 *
 * NBP publishes nothing on weekends and Polish public holidays, so the lookup
 * walks backwards day by day from D-1 until a quotation is found or the window
 * is exhausted (which also bounds the number of requests).
 */
final readonly class NbpApiRateProvider implements NbpRateProviderInterface
{
    /**
     * Longest run of consecutive non-publication days to tolerate. Ten calendar
     * days comfortably covers Christmas/New Year and the May long weekend.
     */
    private const int MAX_LOOKBACK_DAYS = 10;

    public function __construct(private HttpClientInterface $httpClient)
    {
    }

    public function rateForPreviousBusinessDay(string $currency, DateTimeImmutable $transactionDate): NbpRate
    {
        // The code goes straight into the URL path; allow only ISO 4217 shapes.
        if (1 !== preg_match('/^[A-Za-z]{3}$/', $currency)) {
            throw ExchangeRateUnavailableException::forCurrency($currency, $transactionDate);
        }

        $candidate = $transactionDate->modify('-1 day');

        for ($attempt = 0; $attempt < self::MAX_LOOKBACK_DAYS; ++$attempt) {
            $rate = $this->fetch($currency, $candidate, $transactionDate);
            if (null !== $rate) {
                return $rate;
            }

            $candidate = $candidate->modify('-1 day');
        }

        throw ExchangeRateUnavailableException::forCurrency($currency, $transactionDate);
    }

    /**
     * @return NbpRate|null null when NBP has no quotation for that exact day
     */
    private function fetch(string $currency, DateTimeImmutable $day, DateTimeImmutable $transactionDate): ?NbpRate
    {
        $path = sprintf(
            'exchangerates/rates/a/%s/%s/',
            strtolower($currency),
            $day->format('Y-m-d'),
        );

        try {
            $response = $this->httpClient->request('GET', $path, [
                'query' => ['format' => 'json'],
            ]);

            $status = $response->getStatusCode();
            if (404 === $status) {
                return null;
            }

            if (200 !== $status) {
                throw ExchangeRateUnavailableException::forCurrency($currency, $transactionDate);
            }

            /** @var array<string, mixed> $payload */
            $payload = $response->toArray();
        } catch (JsonException $e) {
            throw ExchangeRateUnavailableException::forCurrency($currency, $transactionDate, $e);
        } catch (HttpExceptionInterface $e) {
            throw ExchangeRateUnavailableException::forCurrency($currency, $transactionDate, $e);
        }

        return $this->toRate($payload, $currency, $transactionDate, $day);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function toRate(
        array $payload,
        string $currency,
        DateTimeImmutable $transactionDate,
        DateTimeImmutable $requestedDay,
    ): NbpRate
    {
        $payloadCode = $payload['code'] ?? null;
        if (!is_string($payloadCode) || strtoupper($payloadCode) !== strtoupper($currency)) {
            throw ExchangeRateUnavailableException::forCurrency($currency, $transactionDate);
        }

        $rates = $payload['rates'] ?? null;
        if (!is_array($rates) || [] === $rates) {
            throw ExchangeRateUnavailableException::forCurrency($currency, $transactionDate);
        }

        $first = reset($rates);
        if (!is_array($first) || !isset($first['mid'], $first['effectiveDate'])) {
            throw ExchangeRateUnavailableException::forCurrency($currency, $transactionDate);
        }

        $mid = $first['mid'];
        if (!is_float($mid) && !is_int($mid) && !is_string($mid)) {
            throw ExchangeRateUnavailableException::forCurrency($currency, $transactionDate);
        }

        $effectiveDate = $first['effectiveDate'];
        if (!is_string($effectiveDate)) {
            throw ExchangeRateUnavailableException::forCurrency($currency, $transactionDate);
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveDate);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (
            false === $date
            || (false !== $dateErrors && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $date->format('Y-m-d') !== $effectiveDate
            || $date->format('Y-m-d') !== $requestedDay->format('Y-m-d')
        ) {
            throw ExchangeRateUnavailableException::forCurrency($currency, $transactionDate);
        }

        $table = $first['no'] ?? null;

        try {
            $rate = Decimal::of(is_float($mid) ? self::floatToDecimalString($mid) : (string) $mid);
        } catch (InvalidNumberException $e) {
            throw ExchangeRateUnavailableException::forCurrency($currency, $transactionDate, $e);
        }

        if (!$rate->isPositive()) {
            throw ExchangeRateUnavailableException::forCurrency($currency, $transactionDate);
        }

        return new NbpRate(
            strtoupper($currency),
            // JSON numbers arrive as floats; format them back to their exact
            // decimal notation before they enter the decimal domain.
            $rate,
            $date,
            is_string($table) ? $table : null,
        );
    }

    /**
     * NBP publishes rates with four decimal places; `%.4F` reproduces the
     * published value exactly without exposing binary float artefacts.
     */
    private static function floatToDecimalString(float $value): string
    {
        return rtrim(rtrim(sprintf('%.4F', $value), '0'), '.') ?: '0';
    }
}
