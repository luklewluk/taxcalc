<?php

declare(strict_types=1);

namespace App\CurrencyRate;

use App\CurrencyRate\Entity\NbpCoverage;
use App\CurrencyRate\Entity\NbpTableRate;
use App\CurrencyRate\Repository\NbpCoverageRepository;
use App\CurrencyRate\Repository\NbpTableRateRepository;
use App\Exception\ExchangeRateUnavailableException;
use DateTimeImmutable;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Serves NBP rates from published tables stored in the database.
 *
 * A quarter that is not stored yet is fetched in one request - every currency
 * of every table A in it - and kept for good: published quotations do not
 * change, so nothing ever expires and a deploy loses nothing. The D-1 walk
 * happens in SQL over the same ten-day window {@see NbpApiRateProvider} walks
 * over HTTP, which is sound because {@see NbpCoverage} records how far each
 * quarter is complete: inside it, a day without a row is a day NBP published
 * nothing.
 *
 * Only public market data is stored - never anything derived from an uploaded
 * statement. A database that is down or was never migrated costs speed, not the
 * result: the rate is then read from NBP directly for the rest of the request.
 */
final class DatabaseNbpRateProvider implements NbpRateProviderInterface
{
    /**
     * @var array<string, NbpRate> keyed `CURRENCY|Y-m-d` of the transaction
     */
    private array $resolved = [];

    /**
     * @var array<string, DateTimeImmutable> quarter id => stored through
     */
    private array $coverage = [];

    private bool $databaseAvailable = true;

    public function __construct(
        private readonly NbpApiRateProvider $api,
        private readonly NbpTableRateRepository $rates,
        private readonly NbpCoverageRepository $coverages,
        private readonly ManagerRegistry $registry,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function rateForPreviousBusinessDay(string $currency, DateTimeImmutable $transactionDate): NbpRate
    {
        if (1 !== preg_match('/^[A-Za-z]{3}$/', $currency)) {
            throw ExchangeRateUnavailableException::forCurrency($currency, $transactionDate);
        }

        $currency = strtoupper($currency);
        $day = $transactionDate->setTime(0, 0);
        $key = NbpTableRateRepository::key($currency, $day);
        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        // Only a table published before today is final: today's may not be out
        // yet, and storing its absence would hide it for good.
        $last = $day->modify('-1 day');
        $yesterday = $this->clock->now()->setTime(0, 0)->modify('-1 day');
        if (!$this->databaseAvailable || $last->format('Y-m-d') > $yesterday->format('Y-m-d')) {
            return $this->api->rateForPreviousBusinessDay($currency, $transactionDate);
        }

        $first = $day->modify(sprintf('-%d days', NbpApiRateProvider::MAX_LOOKBACK_DAYS));

        try {
            foreach (Quarter::spanning($first, $last) as $quarter) {
                $this->cover($quarter, min($quarter->end, $last), $yesterday);
            }

            $stored = $this->rates->latestInWindow($currency, $first, $last);
        } catch (ExchangeRateUnavailableException $e) {
            throw ExchangeRateUnavailableException::forCurrency($currency, $transactionDate, $e);
        } catch (DbalException|ORMException $e) {
            $this->databaseAvailable = false;
            $this->logger->warning('NBP rate store unavailable, reading NBP directly: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return $this->api->rateForPreviousBusinessDay($currency, $transactionDate);
        }

        if (null === $stored) {
            throw ExchangeRateUnavailableException::forCurrency($currency, $transactionDate);
        }

        return $this->resolved[$key] = $stored->toNbpRate();
    }

    /**
     * Makes sure the quarter is stored at least through `$through`, fetching
     * whatever is missing up to the end of the quarter or yesterday.
     */
    private function cover(Quarter $quarter, DateTimeImmutable $through, DateTimeImmutable $yesterday): void
    {
        $covered = $this->coverage[$quarter->id] ?? null;
        if (null === $covered || $covered < $through) {
            // Another request may have extended it since.
            $covered = $this->coverages->find($quarter->id)?->coveredThrough();
        }

        if (null !== $covered && $covered >= $through) {
            $this->coverage[$quarter->id] = $covered;

            return;
        }

        $from = null === $covered ? $quarter->start : $covered->modify('+1 day');
        $to = min($quarter->end, $yesterday);

        $this->store($quarter, $from, $to, $this->api->tablesBetween($from, $to));
        $this->coverage[$quarter->id] = $to;
    }

    /**
     * Stores the fetched quotations and the coverage they prove in one
     * transaction, so coverage never claims a day whose rates are missing.
     *
     * @param list<NbpRate> $tables
     */
    private function store(Quarter $quarter, DateTimeImmutable $from, DateTimeImmutable $to, array $tables): void
    {
        try {
            $this->write($quarter, $from, $to, $tables);
        } catch (UniqueConstraintViolationException) {
            // Another request stored the same quarter first. The quotations are
            // immutable, so its rows are as good as ours: start over with a
            // fresh entity manager (the failed one is closed) and add only what
            // is still missing.
            $this->registry->resetManager();
            $this->write($quarter, $from, $to, $tables);
        }
    }

    /**
     * @param list<NbpRate> $tables
     */
    private function write(Quarter $quarter, DateTimeImmutable $from, DateTimeImmutable $to, array $tables): void
    {
        $entityManager = $this->registry->getManagerForClass(NbpTableRate::class);
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new \LogicException('NbpTableRate is not managed by the ORM.');
        }

        $entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($quarter, $from, $to, $tables): void {
            $existing = $this->rates->existingKeys($from, $to);
            foreach ($tables as $rate) {
                if (!isset($existing[NbpTableRateRepository::key($rate->currency, $rate->date)])) {
                    $entityManager->persist(NbpTableRate::fromNbpRate($rate));
                }
            }

            $coverage = $this->coverages->find($quarter->id);
            if (null === $coverage) {
                $entityManager->persist(new NbpCoverage($quarter->id, $to));
            } else {
                $coverage->extendTo($to);
            }
        });

        // Thousands of quotations need not stay managed for the rest of the request.
        $entityManager->clear();
    }
}
