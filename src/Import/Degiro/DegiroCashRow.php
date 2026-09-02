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
         * What identifies the payment this row belongs to: instrument, currency, the *value date* and
         * the *year of the booking date*.
         *
         * The value date says which payment a row describes, so DEGIRO's
         * corrections keep netting against the payment they correct - it
         * reverses one on one day and re-posts it on the next. The booking year
         * is in the key as well, because a reversal posted in a later year must
         * not reach back and empty the year the original was settled in: within
         * one year corrections net, across years the parts stay apart.
         */
        public string $paymentKey,
        public string $name,
        public string $isin,
        public string $currency,
        /** The day the cash reached the account, i.e. DEGIRO's booking date. */
        public DateTimeImmutable $date,
        /**
         * The issuer's payable date, when the export names it separately.
         * Audit-only: it never settles a year, but a reversal needs it to say
         * which year the correction belongs to.
         */
        public ?DateTimeImmutable $valueDate,
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
