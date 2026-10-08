<?php

declare(strict_types=1);

namespace App\Web\Lots;

use App\Fifo\FifoMatcher;
use App\Fifo\LotAssignments;
use App\Fifo\LotMethod;
use App\Fifo\Trade;
use App\Money\Decimal;
use App\Web\Ledger\LedgerEntry;
use App\Web\Ledger\TradeLedger;

/**
 * Turns the Transakcje ledger - already grouped and sorted exactly like the
 * FIFO queues - into the FIFO tab's two panes: the lots that opened positions
 * on the left, the closings on the right, each linked to its counterparts.
 */
final readonly class LotBoardBuilder
{
    public function __construct(private FifoMatcher $matcher)
    {
    }

    /**
     * @param list<Trade> $trades the valid trades the ledger was settled from
     */
    public function build(
        TradeLedger $ledger,
        LotAssignments $assignments,
        array $trades,
        ?LotEditorRequest $editorRequest,
        bool $fieldValid,
    ): LotBoard {
        $valid = [];
        foreach ($trades as $trade) {
            $valid[$trade->id()] = $trade;
        }

        $queues = [];
        $lotLabels = [];
        $hasSpecific = false;
        $editorEntry = null;

        foreach ($ledger->sections as $section) {
            foreach ($section->groups as $group) {
                /** @var list<LedgerEntry> $openings */
                $openings = [];
                $closings = [];
                foreach ($group->entries as $entry) {
                    [$opens, $closes] = self::sides($entry);
                    if ($opens) {
                        $openings[] = $entry;
                    }
                    if ($closes) {
                        $closings[] = $entry;
                    }
                }

                $openLabels = [];
                foreach ($openings as $i => $entry) {
                    $openLabels[$entry->id] = 'P'.($i + 1);
                }
                $closeLabels = [];
                foreach ($closings as $i => $entry) {
                    $closeLabels[$entry->id] = 'Z'.($i + 1);
                }
                $lotLabels += $openLabels;

                $lefts = [];
                foreach ($openings as $entry) {
                    $links = [];
                    foreach ($entry->detail->closedBy ?? [] as $match) {
                        $links[] = new LotLink(
                            $closeLabels[$match->counterpartId] ?? '?',
                            $match->counterpartId,
                            $match->match->quantity,
                            $match->match->lotMethod,
                        );
                    }
                    $lefts[] = new LotOpening(
                        $openLabels[$entry->id],
                        $entry->id,
                        $entry->row,
                        self::quantity($valid[$entry->id] ?? null),
                        $entry->detail->openQuantity ?? Decimal::zero(),
                        $links,
                    );
                }

                $expanded = false;
                $rights = [];
                foreach ($closings as $entry) {
                    $links = [];
                    $specific = $assignments->has($entry->id);
                    foreach ($entry->detail->closes ?? [] as $match) {
                        $links[] = new LotLink(
                            $openLabels[$match->counterpartId] ?? '?',
                            $match->counterpartId,
                            $match->match->quantity,
                            $match->match->lotMethod,
                        );
                        $specific = $specific || LotMethod::Specific === $match->match->lotMethod;
                    }

                    $problems = $entry->detail->notices ?? [];
                    foreach ($entry->messages as $message) {
                        if (str_starts_with($message->code, 'fifo.lot_') || str_starts_with($message->code, 'lots.')) {
                            $problems[] = $message->message;
                        }
                    }
                    $problems = array_values(array_unique($problems));

                    $editable = !$group->isOption && !$group->mixedKinds && $ledger->fifoAvailable && $fieldValid
                        && isset($valid[$entry->id]) && $valid[$entry->id]->isSell();
                    $unmatched = $entry->detail?->unmatchedQuantity;

                    $rights[] = new LotClosing(
                        $closeLabels[$entry->id],
                        $entry->id,
                        $entry->row,
                        self::quantity($valid[$entry->id] ?? null),
                        $specific,
                        $links,
                        $unmatched,
                        $problems,
                        $editable,
                    );

                    $hasSpecific = $hasSpecific || $specific;
                    $expanded = $expanded || $specific || [] !== $problems || null !== $unmatched;
                    if (null !== $editorRequest && $editorRequest->saleId === $entry->id) {
                        $expanded = true;
                        $editorEntry = [$entry, $closeLabels[$entry->id]];
                    }
                }

                $queues[] = new LotQueue(
                    $group->key,
                    $group->broker,
                    $group->title,
                    $group->isOption,
                    $group->mixedKinds,
                    $expanded,
                    $lefts,
                    $rights,
                );
            }
        }

        $editor = null;
        if (null !== $editorRequest && null !== $editorEntry && isset($valid[$editorRequest->saleId])) {
            $editor = $this->editor($editorRequest, $editorEntry[0], $editorEntry[1], $valid, $lotLabels, $assignments, $trades);
        }

        return new LotBoard($queues, $ledger->fifoAvailable, $fieldValid, $hasSpecific, $editor);
    }

    /**
     * @param array<string, Trade>  $valid
     * @param array<string, string> $lotLabels
     * @param list<Trade>           $trades
     */
    private function editor(
        LotEditorRequest $request,
        LedgerEntry $entry,
        string $label,
        array $valid,
        array $lotLabels,
        LotAssignments $assignments,
        array $trades,
    ): ?LotEditor {
        $open = $this->matcher->openLotsBefore($trades, $assignments, $request->saleId);
        if (null === $open) {
            return null;
        }

        // What to prefill: the values of a failed save, else what is stored,
        // else what FIFO picked - so editing starts from the current answer.
        $stored = [];
        foreach ($assignments->for($request->saleId) ?? [] as $allocation) {
            $stored[$allocation->buyTradeId] = (string) $allocation->quantity;
        }
        $fifo = [];
        foreach ($entry->detail->closes ?? [] as $match) {
            $fifo[$match->counterpartId] = (string) $match->match->quantity;
        }
        $current = [] !== $request->values ? $request->values : ([] !== $stored ? $stored : $fifo);

        $available = [];
        foreach ($open as $position) {
            $available[$position->trade->id()] = [$position->trade, $position->quantity];
        }
        // A stored lot that has nothing left at this sale (an earlier sale took
        // it) stays visible, so it can be set to zero.
        foreach (array_keys($current) as $lotId) {
            if (!isset($available[$lotId]) && isset($valid[$lotId]) && $valid[$lotId]->isBuy()) {
                $available[$lotId] = [$valid[$lotId], Decimal::zero()];
            }
        }

        $rows = [];
        $index = 0;
        foreach ($trades as $trade) {
            if (!isset($available[$trade->id()])) {
                continue;
            }
            [$lot, $quantity] = $available[$trade->id()];
            $rows[] = new LotEditorRow(
                $index++,
                $lot->id(),
                $lotLabels[$lot->id()] ?? '?',
                ['date' => $lot->date->format('Y-m-d'), 'quantity' => (string) $lot->quantity->abs()],
                $quantity,
                $current[$lot->id()] ?? '',
            );
        }
        usort($rows, static fn (LotEditorRow $a, LotEditorRow $b): int => [$a->row['date'], $a->index] <=> [$b->row['date'], $b->index]);
        $rows = array_values(array_map(
            static fn (LotEditorRow $row, int $i): LotEditorRow => new LotEditorRow($i, $row->tradeId, $row->label, $row->row, $row->available, $row->value),
            $rows,
            array_keys($rows),
        ));

        return new LotEditor(
            $request->saleId,
            $label,
            $entry->row,
            $valid[$request->saleId]->quantity->abs(),
            $rows,
            $request->errors,
        );
    }

    /**
     * Which pane a row belongs to. A stock buy opens and a stock sale closes; an
     * option says so itself, and a close-then-open is both.
     *
     * @return array{bool, bool}
     */
    private static function sides(LedgerEntry $entry): array
    {
        $side = strtoupper($entry->row['side'] ?? '');
        if (!$entry->isOption) {
            return ['BUY' === $side, 'SELL' === $side];
        }

        return match ($entry->row['effect'] ?? '') {
            'open' => [true, false],
            'close' => [false, true],
            'close_open' => [true, true],
            default => ['BUY' === $side, 'SELL' === $side],
        };
    }

    private static function quantity(?Trade $trade): ?Decimal
    {
        return $trade?->quantity->abs();
    }
}
