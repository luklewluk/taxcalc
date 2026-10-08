<?php

declare(strict_types=1);

namespace App\CurrencyRate\Repository;

use App\CurrencyRate\Entity\NbpTableRate;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NbpTableRate>
 */
final class NbpTableRateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NbpTableRate::class);
    }

    /**
     * The most recent quotation of `$currency` published between the two days,
     * inclusive.
     */
    public function latestInWindow(string $currency, DateTimeImmutable $from, DateTimeImmutable $to): ?NbpTableRate
    {
        /** @var NbpTableRate|null */
        return $this->createQueryBuilder('r')
            ->where('r.currency = :currency')
            ->andWhere('r.effectiveDate BETWEEN :from AND :to')
            ->orderBy('r.effectiveDate', 'DESC')
            ->setMaxResults(1)
            ->setParameter('currency', $currency)
            ->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->setParameter('to', $to, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Which quotations between the two days are already stored.
     *
     * @return array<string, true> keyed `CURRENCY|Y-m-d`
     */
    public function existingKeys(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        /** @var list<array{currency: string, effectiveDate: DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('r.currency', 'r.effectiveDate')
            ->where('r.effectiveDate BETWEEN :from AND :to')
            ->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->setParameter('to', $to, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getArrayResult();

        $keys = [];
        foreach ($rows as $row) {
            $keys[self::key($row['currency'], $row['effectiveDate'])] = true;
        }

        return $keys;
    }

    public static function key(string $currency, DateTimeImmutable $day): string
    {
        return $currency.'|'.$day->format('Y-m-d');
    }
}
