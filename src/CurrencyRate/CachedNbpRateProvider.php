<?php

declare(strict_types=1);

namespace App\CurrencyRate;

use App\Money\Decimal;
use DateTimeImmutable;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Caches published NBP quotations.
 *
 * Only public market data is stored - never anything derived from an uploaded
 * statement - so the cache is safe to share between requests and users.
 */
final readonly class CachedNbpRateProvider implements NbpRateProviderInterface
{
    public function __construct(
        private NbpRateProviderInterface $delegate,
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function rateForPreviousBusinessDay(string $currency, DateTimeImmutable $transactionDate): NbpRate
    {
        $key = sprintf(
            'nbp.a.%s.%s',
            preg_replace('/[^A-Za-z]/', '', $currency) ?? '',
            $transactionDate->format('Y-m-d'),
        );

        $item = $this->cache->getItem($key);
        if ($item->isHit()) {
            /** @var array{rate: string, date: string, table: string|null}|mixed $cached */
            $cached = $item->get();
            if (is_array($cached) && isset($cached['rate'], $cached['date'])) {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $cached['date']);
                if (false !== $date) {
                    /** @var string|null $table */
                    $table = $cached['table'] ?? null;

                    return new NbpRate(strtoupper($currency), Decimal::of((string) $cached['rate']), $date, $table);
                }
            }
        }

        $rate = $this->delegate->rateForPreviousBusinessDay($currency, $transactionDate);

        $item->set([
            'rate' => (string) $rate->rate,
            'date' => $rate->date->format('Y-m-d'),
            'table' => $rate->table,
        ]);
        $this->cache->save($item);

        return $rate;
    }
}
