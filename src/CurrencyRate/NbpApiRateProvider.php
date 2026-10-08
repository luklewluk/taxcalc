<?php

declare(strict_types=1);

namespace App\CurrencyRate;

use App\Exception\ExchangeRateUnavailableException;
use App\Exception\InvalidNumberException;
use App\Money\Decimal;
use DateTimeImmutable;
use InvalidArgumentException;
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
    public const int MAX_LOOKBACK_DAYS = 10;

    /**
     * NBP refuses a table query spanning more than 93 days.
     */
    public const int MAX_RANGE_DAYS = 93;

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
     * Every quotation of every table A published between the two days,
     * inclusive, in publication order.
     *
     * An empty list means NBP published nothing in that range (New Year's Day,
     * a weekend), which is an answer in its own right.
     *
     * @return list<NbpRate>
     *
     * @throws ExchangeRateUnavailableException when NBP cannot be read or answers with anything malformed
     */
    public function tablesBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $from = $from->setTime(0, 0);
        $to = $to->setTime(0, 0);
        if ($from > $to || $from->diff($to)->days >= self::MAX_RANGE_DAYS) {
            throw new InvalidArgumentException(sprintf(
                'A table range runs forward and spans at most %d days, got %s to %s.',
                self::MAX_RANGE_DAYS,
                $from->format('Y-m-d'),
                $to->format('Y-m-d'),
            ));
        }

        $path = sprintf('exchangerates/tables/a/%s/%s/', $from->format('Y-m-d'), $to->format('Y-m-d'));

        try {
            $response = $this->httpClient->request('GET', $path, [
                'query' => ['format' => 'json'],
            ]);

            $status = $response->getStatusCode();
            if (404 === $status) {
                return [];
            }

            if (200 !== $status) {
                throw ExchangeRateUnavailableException::forTables($from, $to);
            }

            $payload = $response->toArray();
        } catch (JsonException $e) {
            throw ExchangeRateUnavailableException::forTables($from, $to, $e);
        } catch (HttpExceptionInterface $e) {
            throw ExchangeRateUnavailableException::forTables($from, $to, $e);
        }

        if (!array_is_list($payload)) {
            throw ExchangeRateUnavailableException::forTables($from, $to);
        }

        $rates = [];
        $days = [];
        foreach ($payload as $table) {
            if (!is_array($table) || 'A' !== ($table['table'] ?? null)) {
                throw ExchangeRateUnavailableException::forTables($from, $to);
            }

            $date = self::parseDay($table['effectiveDate'] ?? null);
            if (null === $date || $date < $from || $date > $to || isset($days[$date->format('Y-m-d')])) {
                throw ExchangeRateUnavailableException::forTables($from, $to);
            }
            $days[$date->format('Y-m-d')] = true;

            $tableRates = $table['rates'] ?? null;
            if (!is_array($tableRates) || [] === $tableRates) {
                throw ExchangeRateUnavailableException::forTables($from, $to);
            }

            $number = $table['no'] ?? null;
            $codes = [];
            foreach ($tableRates as $quotation) {
                $code = is_array($quotation) ? ($quotation['code'] ?? null) : null;
                $mid = is_array($quotation) ? self::parseMid($quotation['mid'] ?? null) : null;
                if (!is_string($code) || 1 !== preg_match('/^[A-Z]{3}$/', $code) || isset($codes[$code]) || null === $mid) {
                    throw ExchangeRateUnavailableException::forTables($from, $to);
                }
                $codes[$code] = true;

                $rates[] = new NbpRate($code, $mid, $date, is_string($number) ? $number : null);
            }
        }

        return $rates;
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

        $date = self::parseDay($first['effectiveDate']);
        if (null === $date || $date->format('Y-m-d') !== $requestedDay->format('Y-m-d')) {
            throw ExchangeRateUnavailableException::forCurrency($currency, $transactionDate);
        }

        $rate = self::parseMid($first['mid']);
        if (null === $rate) {
            throw ExchangeRateUnavailableException::forCurrency($currency, $transactionDate);
        }

        $table = $first['no'] ?? null;

        return new NbpRate(
            strtoupper($currency),
            $rate,
            $date,
            is_string($table) ? $table : null,
        );
    }

    /**
     * A strict `Y-m-d` day, or null for anything else (`2025-02-31` included).
     */
    private static function parseDay(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            false === $date
            || (false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            return null;
        }

        return $date;
    }

    /**
     * A positive rate, or null for anything else.
     *
     * JSON numbers arrive as floats; they are formatted back to their exact
     * decimal notation before they enter the decimal domain.
     */
    private static function parseMid(mixed $mid): ?Decimal
    {
        if (!is_float($mid) && !is_int($mid) && !is_string($mid)) {
            return null;
        }

        try {
            $rate = Decimal::of(is_float($mid) ? self::floatToDecimalString($mid) : (string) $mid);
        } catch (InvalidNumberException) {
            return null;
        }

        return $rate->isPositive() ? $rate : null;
    }

    /**
     * Table A quotes per unit with up to eight decimal places: four for most
     * currencies, six for JPY, HUF, KRW, CLP and ISK, eight for IDR. `%.8F`
     * reproduces every one of them exactly without exposing binary float
     * artefacts; the trailing zeros it adds are trimmed again.
     */
    private static function floatToDecimalString(float $value): string
    {
        return rtrim(rtrim(sprintf('%.8F', $value), '0'), '.') ?: '0';
    }
}
