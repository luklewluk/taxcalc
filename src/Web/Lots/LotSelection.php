<?php

declare(strict_types=1);

namespace App\Web\Lots;

use App\Exception\InvalidNumberException;
use App\Fifo\FifoMatcher;
use App\Fifo\LotAllocation;
use App\Fifo\LotAssignments;
use App\Fifo\Trade;
use App\Import\Parser\NumberParser;
use App\Money\Decimal;
use App\Web\Diagnostic;

/**
 * Reads the named lots from a workbench post and applies at most one lot
 * action - every one of them a real submit, never client-side state.
 *
 * - `lot_edit=<sale id>`  opens the editor of that one sale (its inputs are the
 *   only per-lot fields the form ever carries);
 * - `lot_save=<sale id>`  stores the quantities typed into that editor;
 * - `lot_fifo=<sale id>`  returns the sale to FIFO;
 * - `lot_reset`           returns every sale to FIFO (also the way out of a
 *   field that cannot be read).
 */
final readonly class LotSelection
{
    public function __construct(
        private LotAssignmentCodec $codec,
        private FifoMatcher $matcher,
    ) {
    }

    /**
     * @param array<mixed>                $post   the whole request body
     * @param list<Trade>                 $trades the valid trade rows
     * @param list<array<string, string>> $rows   every kept trade row, valid or not
     */
    public function read(array $post, array $trades, array $rows, bool $tradesComplete): LotSelectionResult
    {
        if ('' !== self::scalar($post['lot_reset'] ?? null)) {
            return new LotSelectionResult(new LotAssignments(), '', true);
        }

        $raw = $post['lot_assignments'] ?? null;
        $decoded = $this->codec->decode($raw);
        if (!$decoded->valid) {
            $message = (string) $decoded->message;

            // Echoed back unchanged, so the block survives the next submit
            // instead of quietly turning into FIFO.
            return new LotSelectionResult(
                new LotAssignments(),
                is_string($raw) ? $raw : '',
                false,
                null,
                [$message],
                [Diagnostic::blocking('lots.invalid_field', $message, 'fifo')],
            );
        }

        [$assignments, $diagnostics] = $this->prune($decoded->assignments, $trades, $rows);

        $fifo = self::scalar($post['lot_fifo'] ?? null);
        $save = self::scalar($post['lot_save'] ?? null);
        $edit = self::scalar($post['lot_edit'] ?? null);
        $editor = null;

        if ('' !== $fifo) {
            $assignments = $assignments->without($fifo);
        } elseif ('' !== $save) {
            [$assignments, $editor] = $this->save($save, $post, $assignments, $trades);
        } elseif ('' !== $edit) {
            if ($tradesComplete && null !== self::stockSale($edit, $trades)) {
                $editor = new LotEditorRequest($edit);
            } else {
                // A mis-click must never take a result off the screen.
                $diagnostics[] = Diagnostic::review(
                    'lots.edit_unavailable',
                    'Partie można wskazać tylko dla poprawnej sprzedaży akcji lub ETF-u - najpierw popraw transakcje.',
                    'fifo',
                );
            }
        }

        return new LotSelectionResult($assignments, $this->codec->encode($assignments), true, $editor, [], $diagnostics);
    }

    /**
     * Drops what no longer names a sale. Keyed on the posted rows, not the
     * valid trades: a typo elsewhere in a sale's row must not cost it its lots.
     *
     * @param list<Trade>                 $trades
     * @param list<array<string, string>> $rows
     *
     * @return array{LotAssignments, list<Diagnostic>}
     */
    private function prune(LotAssignments $assignments, array $trades, array $rows): array
    {
        $rowIds = [];
        foreach ($rows as $row) {
            $rowIds[$row['id'] ?? ''] = true;
        }
        $byId = [];
        foreach ($trades as $trade) {
            $byId[$trade->id()] = $trade;
        }

        $diagnostics = [];
        foreach ($assignments->saleIds() as $saleId) {
            if (!isset($rowIds[$saleId])) {
                // The sale was deleted: its lots go with it.
                $assignments = $assignments->without($saleId);

                continue;
            }

            $trade = $byId[$saleId] ?? null;
            if (null !== $trade && ($trade->isOption() || !$trade->isSell())) {
                $assignments = $assignments->without($saleId);
                $diagnostics[] = Diagnostic::review(
                    'lots.assignment_dropped',
                    sprintf(
                        'Transakcja %s z dnia %s nie jest już sprzedażą akcji, więc wskazane dla niej partie '
                        .'usunięto - rozlicza się według FIFO.',
                        $trade->symbol,
                        $trade->date->format('Y-m-d'),
                    ),
                    'fifo',
                    $saleId,
                );
            }
        }

        return [$assignments, $diagnostics];
    }

    /**
     * @param array<mixed> $post
     * @param list<Trade>  $trades
     *
     * @return array{LotAssignments, LotEditorRequest|null}
     */
    private function save(string $saleId, array $post, LotAssignments $assignments, array $trades): array
    {
        $sale = self::stockSale($saleId, $trades);
        if (null === $sale) {
            return [$assignments, new LotEditorRequest($saleId, [], ['Tej sprzedaży nie można już edytować - popraw najpierw transakcje.'])];
        }

        $ids = self::lotIds($post['lot_edit_ids'] ?? null);
        $quantities = is_array($post['lot_edit_qty'] ?? null) ? array_values($post['lot_edit_qty']) : [];
        $count = self::scalar($post['lot_edit_count'] ?? null);

        if (null === $ids || (string) count($ids) !== $count || count($quantities) !== count($ids)) {
            return [$assignments, new LotEditorRequest($saleId, [], ['Formularz partii dotarł niekompletny - otwórz edycję jeszcze raz.'])];
        }

        $values = [];
        $allocations = [];
        $errors = [];
        $sum = Decimal::zero();
        foreach ($ids as $i => $lotId) {
            $typed = trim(self::scalar($quantities[$i]));
            $values[$lotId] = $typed;
            if ('' === $typed) {
                continue;
            }

            try {
                $quantity = NumberParser::parse($typed, decimalComma: true);
            } catch (InvalidNumberException) {
                $errors[] = sprintf('„%s” nie jest liczbą sztuk.', $typed);

                continue;
            }

            if ($quantity->isNegative()) {
                $errors[] = sprintf('Liczba sztuk nie może być ujemna (%s).', $typed);

                continue;
            }
            if ($quantity->isZero()) {
                continue;
            }

            $allocations[] = new LotAllocation($lotId, $quantity);
            $sum = $sum->plus($quantity);
        }

        $sold = $sale->quantity->abs();
        if ([] === $errors && [] === $allocations) {
            $errors[] = 'Wskaż co najmniej jedną partię albo przywróć FIFO.';
        } elseif ([] === $errors && 0 !== $sum->compareTo($sold)) {
            $errors[] = sprintf('Sprzedano %s szt., a wskazane partie sumują się do %s szt.', (string) $sold, (string) $sum);
        }

        if ([] === $errors) {
            $tentative = $assignments->with($saleId, $allocations);
            foreach ($this->matcher->match($trades, $tentative)->violations as $violation) {
                // Only the edited sale: others it disturbs are reported, not
                // refused - or two sales could never swap lots.
                if ($violation->tradeId === $saleId && $violation->kind->isLotAssignment()) {
                    $errors[] = $violation->describe();
                }
            }

            if ([] === $errors) {
                return [$tentative, null];
            }
        }

        return [$assignments, new LotEditorRequest($saleId, $values, $errors)];
    }

    /**
     * @param list<Trade> $trades
     */
    private static function stockSale(string $id, array $trades): ?Trade
    {
        foreach ($trades as $trade) {
            if ($trade->id() === $id) {
                return $trade->isSell() && !$trade->isOption() ? $trade : null;
            }
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private static function lotIds(mixed $raw): ?array
    {
        if (!is_string($raw) || strlen($raw) > 1_048_576) {
            return null;
        }

        try {
            $ids = json_decode($raw, true, 2, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($ids) || !array_is_list($ids)) {
            return null;
        }
        foreach ($ids as $id) {
            if (!is_string($id) || '' === $id || strlen($id) > 128) {
                return null;
            }
        }

        return $ids;
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
