<?php

declare(strict_types=1);

namespace App\Import\Degiro;

use App\Money\Decimal;
use DateTimeImmutable;

/**
 * One dividend or withholding-tax row read from a DEGIRO account statement,
 * after classification but before the payment is assembled.
 *
 * Rows have to be collected across every uploaded statement before anything is
 * built, because a payment and the tax on it are two rows that can easily land
 * in two different exports; see
 * {@see \App\Import\Importer\DegiroAccountImporter}.
 */
final readonly class DegiroCashRow
{
    public function __construct(
        public bool $isTax,
        /**
         * Instrument, currency and *value date* - the three things that identify
         * the payment this row belongs to.
         */
        public string $paymentKey,
        public string $name,
        public string $isin,
        public string $currency,
        public DateTimeImmutable $date,
        public Decimal $amount,
        public string $source,
        public int $line,
        /**
         * Which occurrence of this exact row it is within the file it came from,
         * counted from 1. Two identical payments on one day are two payments,
         * and their position inside one export is the only thing telling them
         * apart when a second, overlapping export is uploaded too.
         */
        public int $ordinal,
    ) {
    }

    /**
     * Identity of this row as a row, independent of which file it arrived in.
     */
    public function signature(): string
    {
        return implode('|', [
            $this->isTax ? 'tax' : 'gross',
            $this->paymentKey,
            (string) $this->amount,
            (string) $this->ordinal,
        ]);
    }
}
