<?php

declare(strict_types=1);

namespace App\CurrencyRate\Entity;

use App\CurrencyRate\Repository\NbpCoverageRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * How far into a quarter the stored tables are complete.
 *
 * Up to `coveredThrough`, a day without a row in `nbp_rate` is a day NBP
 * published nothing - a weekend or a holiday - rather than a day nobody asked
 * about yet. It is written in the same transaction as the rates it vouches for.
 */
#[ORM\Entity(repositoryClass: NbpCoverageRepository::class)]
#[ORM\Table(name: 'nbp_coverage')]
class NbpCoverage
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 7)]
        private string $quarter,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private DateTimeImmutable $coveredThrough,
    ) {
    }

    public function quarter(): string
    {
        return $this->quarter;
    }

    public function coveredThrough(): DateTimeImmutable
    {
        return $this->coveredThrough;
    }

    /**
     * Coverage only ever grows: published tables do not disappear.
     */
    public function extendTo(DateTimeImmutable $day): void
    {
        if ($day > $this->coveredThrough) {
            $this->coveredThrough = $day;
        }
    }
}
