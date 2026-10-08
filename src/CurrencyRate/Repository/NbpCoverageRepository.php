<?php

declare(strict_types=1);

namespace App\CurrencyRate\Repository;

use App\CurrencyRate\Entity\NbpCoverage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NbpCoverage>
 */
final class NbpCoverageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NbpCoverage::class);
    }
}
