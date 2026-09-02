<?php

declare(strict_types=1);

namespace App\Model;

use App\Exception\InvalidRecordException;
use App\Money\Amount;
use DateTimeImmutable;

/** A standalone brokerage-account charge or its correction/refund. */
final readonly class AccountFee
{
    public function __construct(
        public string $description,
        public string $category,
        /**
         * The day the charge left the cash balance, which is the day it settles
         * in. Named after DEGIRO's column for historical reasons; the DEGIRO
         * importer fills it from the *booking* date, for the same reason a
         * dividend does - see {@see \App\Import\Importer\DegiroAccountImporter}.
         */
        public DateTimeImmutable $valueDate,
        public string $currency,
        public Amount $amount,
        public bool $correction,
        public string $source,
        public string $stableId,
        public bool $included = true,
    ) {
        if ('' === trim($description)) {
            throw new InvalidRecordException('Opis opłaty jest wymagany.');
        }
        if ($amount->currency() !== $currency) {
            throw InvalidRecordException::currencyMismatch('kwota opłaty', $currency, $amount->currency());
        }
        if (!$amount->isPositive()) {
            throw InvalidRecordException::amountMustBePositive('kwota opłaty', $amount);
        }
    }

    public function taxYear(): int
    {
        return (int) $this->valueDate->format('Y');
    }

    public function id(): string
    {
        if ('' !== $this->stableId) {
            return $this->stableId;
        }

        return hash('sha256', implode('|', [
            'fee', $this->description, $this->category, $this->valueDate->format('Y-m-d'),
            $this->currency, (string) $this->amount->value(), $this->correction ? '1' : '0',
        ]));
    }
}
