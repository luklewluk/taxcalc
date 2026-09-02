<?php

declare(strict_types=1);

namespace App\Model;

use App\Exception\InvalidRecordException;
use App\Money\Amount;
use DateTimeImmutable;

/**
 * A normalized dividend payment: gross amount plus the tax already withheld at
 * source (always stored as a positive number).
 */
final readonly class Dividend
{
    public function __construct(
        public string $name,
        public string $countryCode,
        public string $currency,
        public DateTimeImmutable $date,
        public Amount $grossAmount,
        public Amount $withheldTax,
        public string $source,
        /** Stable browser-form identity; the content fingerprint remains available for import deduplication. */
        public string $stableId = '',
    ) {
        if ($grossAmount->currency() !== $currency) {
            throw InvalidRecordException::currencyMismatch('kwota brutto', $currency, $grossAmount->currency());
        }

        if (!$grossAmount->isPositive()) {
            // Never silently flip the sign: a negative gross is a reversal or a
            // misread column, not taxable income.
            throw InvalidRecordException::amountMustBePositive('kwota brutto', $grossAmount);
        }

        if ($withheldTax->currency() !== $currency) {
            throw InvalidRecordException::currencyMismatch('podatek u źródła', $currency, $withheldTax->currency());
        }

        // Broker exports report withholding as a negative number; normalising
        // that sign is the importer's job, so here it must already be >= 0.
        if ($withheldTax->isNegative()) {
            throw InvalidRecordException::amountMustNotBeNegative('podatek u źródła', $withheldTax);
        }

        if ($withheldTax->compareTo($grossAmount) > 0) {
            throw InvalidRecordException::amountMustNotExceed(
                'podatek u źródła',
                $withheldTax,
                'kwota brutto',
                $grossAmount,
            );
        }
    }

    public function taxYear(): int
    {
        return (int) $this->date->format('Y');
    }

    public function fingerprint(): string
    {
        return hash('sha256', implode('|', [
            'dividend',
            $this->name,
            $this->countryCode,
            $this->currency,
            $this->date->format('Y-m-d'),
            (string) $this->grossAmount->value(),
            (string) $this->withheldTax->value(),
        ]));
    }

    public function id(): string
    {
        return '' === $this->stableId ? $this->fingerprint() : $this->stableId;
    }
}
