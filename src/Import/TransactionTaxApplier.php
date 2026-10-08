<?php

declare(strict_types=1);

namespace App\Import;

use App\Fifo\Trade;
use App\Import\Dto\TransactionTax;
use App\Money\Amount;
use App\Money\Decimal;

/**
 * Adds each transaction tax to the purchase it was charged on.
 *
 * The statement row names the paper and the day, not the order - its
 * identifier is its own - so the purchase is found by broker, ISIN and day:
 * the same day first, else the latest purchase day of the week before. Two
 * purchases that day share the tax in proportion to their value. Without a
 * purchase in the same upload nothing is guessed: the tax becomes a review
 * item with its amount, day and paper.
 */
final class TransactionTaxApplier
{
    private const int LOOKBACK_DAYS = 7;

    /**
     * @param list<Trade>          $trades
     * @param list<TransactionTax> $taxes
     *
     * @return array{list<Trade>, list<ImportMessage>}
     */
    public static function apply(array $trades, array $taxes): array
    {
        $messages = [];
        foreach ($taxes as $tax) {
            $candidates = self::purchases($trades, $tax);
            if ([] === $candidates) {
                $messages[] = ImportMessage::review($tax->source, sprintf(
                    '%s %s %s z dnia %s (%s) - w wgranych plikach transakcji nie ma zakupu tego papieru '
                    .'w tej walucie z tego dnia ani z tygodnia wcześniej. Wgraj zestawienie konta razem '
                    .'z plikiem transakcji albo dolicz tę kwotę do Total zakupu w zakładce Transakcje.',
                    $tax->description,
                    (string) $tax->amount->value(),
                    $tax->amount->currency(),
                    $tax->date->format('Y-m-d'),
                    $tax->isin,
                ), $tax->line)->forTab('transactions');

                continue;
            }

            $whole = Decimal::zero();
            foreach ($candidates as $index) {
                $whole = $whole->plus($trades[$index]->grossAmount->value()->abs());
            }

            $left = $tax->amount;
            $last = array_key_last($candidates);
            foreach ($candidates as $position => $index) {
                $slice = $position === $last
                    ? $left
                    : $tax->amount->proratedBy($trades[$index]->grossAmount->value()->abs(), $whole);
                $left = $left->minus($slice);
                $trades[$index] = $trades[$index]->withAddedBuyCost($slice);
            }
        }

        return [array_values($trades), $messages];
    }

    /**
     * Indexes of the purchases a tax belongs to, in file order.
     *
     * @param array<int, Trade> $trades
     *
     * @return list<int>
     */
    private static function purchases(array $trades, TransactionTax $tax): array
    {
        $byDay = [];
        foreach ($trades as $index => $trade) {
            if ($trade->broker !== $tax->broker
                || $trade->symbol !== $tax->isin
                || !$trade->isBuy()
                || $trade->grossAmount->currency() !== $tax->amount->currency()) {
                continue;
            }

            $days = (int) $trade->date->setTime(0, 0)->diff($tax->date->setTime(0, 0))->format('%r%a');
            if ($days >= 0 && $days <= self::LOOKBACK_DAYS) {
                $byDay[$days][] = $index;
            }
        }

        if ([] === $byDay) {
            return [];
        }
        ksort($byDay);

        return reset($byDay);
    }
}
