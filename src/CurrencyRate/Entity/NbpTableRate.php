<?php

declare(strict_types=1);

namespace App\CurrencyRate\Entity;

use App\CurrencyRate\NbpRate;
use App\CurrencyRate\Repository\NbpTableRateRepository;
use App\Money\Decimal;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One quotation of a published NBP table A. Public market data only.
 *
 * The rate is kept as the exact decimal text NBP published: a DECIMAL column
 * would pad it with zeros and change the scale every report prints.
 */
#[ORM\Entity(repositoryClass: NbpTableRateRepository::class)]
#[ORM\Table(name: 'nbp_rate')]
#[ORM\UniqueConstraint(name: 'uniq_nbp_rate_currency_day', columns: ['currency', 'effective_date'])]
class NbpTableRate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    private function __construct(
        #[ORM\Column(length: 3, options: ['fixed' => true])]
        private string $currency,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private DateTimeImmutable $effectiveDate,
        #[ORM\Column(length: 20)]
        private string $mid,
        #[ORM\Column(length: 32, nullable: true)]
        private ?string $tableNo,
    ) {
    }

    public static function fromNbpRate(NbpRate $rate): self
    {
        return new self($rate->currency, $rate->date, (string) $rate->rate, $rate->table);
    }

    public function toNbpRate(): NbpRate
    {
        return new NbpRate($this->currency, Decimal::of($this->mid), $this->effectiveDate, $this->tableNo);
    }

    public function id(): ?int
    {
        return $this->id;
    }
}
