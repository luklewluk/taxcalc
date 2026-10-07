<?php

declare(strict_types=1);

namespace App\Web;

use App\Exception\InvalidRecordException;
use App\Fifo\FifoMatcher;
use App\Fifo\FifoViolationKind;
use App\Fifo\UnmatchedSell;
use App\Fifo\Trade;
use App\Model\ClosedPosition;

/** Re-runs FIFO over the editable logical transactions on every submission. */
final readonly class WorkbenchCalculator
{
    public function __construct(private FifoMatcher $fifoMatcher)
    {
    }

    /**
     * @param list<Trade>          $trades
     * @param list<ClosedPosition> $legacyPositions
     */
    public function settle(array $trades, array $legacyPositions = []): SettlementResult
    {
        $fifo = $this->fifoMatcher->match($trades);
        $positions = $legacyPositions;
        $errors = [];
        $diagnostics = [];

        foreach ($fifo->matches as $match) {
            $instrument = $match->instrument();
            $name = $instrument?->displayName ?: $match->symbol;
            $country = $instrument->countryCode ?? '';
            if ($match->buyCost->currency() !== $match->sellProceeds->currency()) {
                $message = sprintf(
                    'Pozycja %s została kupiona w %s, a sprzedana w %s. Ujednolić walutę można tylko na podstawie danych brokera.',
                    $name,
                    $match->buyCost->currency(),
                    $match->sellProceeds->currency(),
                );
                $errors[] = $message;
                $diagnostics[] = Diagnostic::blocking(
                    'fifo.currency_mismatch',
                    $message,
                    'transactions',
                    $match->sellTradeId ?: null,
                );
                continue;
            }

            try {
                $positions[] = ClosedPosition::fromMatch(
                    $match,
                    $name,
                    $country,
                    self::source($match->buySource, $match->sellSource),
                );
            } catch (InvalidRecordException $e) {
                $message = sprintf('Pozycja %s: %s', $name, $e->getMessage());
                $errors[] = $message;
                $diagnostics[] = Diagnostic::blocking('fifo.invalid_match', $message, 'transactions', $match->sellTradeId ?: null);
            }
        }

        // A sale (or an option close) with nothing to match is left out of the
        // result, never silently: a review item on its row says which one and
        // that an earlier statement is missing. It does not block the result -
        // the user sees every other figure while they add the missing year.
        foreach ($fifo->unmatchedSells as $sell) {
            $diagnostics[] = Diagnostic::review(
                UnmatchedSell::CODE,
                $sell->describe(),
                'transactions',
                $sell->tradeId ?: null,
            );
        }

        foreach ($fifo->violations as $violation) {
            $code = 'fifo.'.$violation->kind->value;
            if (FifoViolationKind::UnmatchedClose === $violation->kind) {
                $diagnostics[] = Diagnostic::review($code, $violation->describe(), 'transactions', $violation->tradeId ?: null);

                continue;
            }

            // The rest contradict the data itself (an open against an open
            // position, a missing open/close, stocks and options in one queue).
            $message = $violation->describe();
            $errors[] = $message;
            $diagnostics[] = Diagnostic::blocking($code, $message, 'transactions', $violation->tradeId ?: null);
        }

        return new SettlementResult($positions, $fifo->matches, $errors, $diagnostics);
    }

    private static function source(string $buy, string $sell): string
    {
        $sources = array_values(array_unique(array_filter([$buy, $sell])));

        return implode(', ', $sources);
    }
}
