<?php

declare(strict_types=1);

namespace App\Web;

use App\Exception\InvalidRecordException;
use App\Fifo\FifoMatcher;
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

        foreach ($fifo->unmatchedSells as $sell) {
            $message = sprintf(
                'Sprzedaż %s z dnia %s (%s szt.) nie ma pokrycia w zakupach tej samej puli FIFO.',
                $sell->symbol,
                $sell->date->format('Y-m-d'),
                (string) $sell->quantity,
            );
            $errors[] = $message;
            $diagnostics[] = Diagnostic::blocking(
                'fifo.unmatched_sell',
                $message,
                'transactions',
                $sell->tradeId ?: null,
            );
        }

        foreach ($fifo->violations as $violation) {
            $message = $violation->describe();
            $errors[] = $message;
            $diagnostics[] = Diagnostic::blocking(
                'fifo.'.$violation->kind->value,
                $message,
                'transactions',
                $violation->tradeId ?: null,
            );
        }

        return new SettlementResult($positions, $fifo->matches, $errors, $diagnostics);
    }

    private static function source(string $buy, string $sell): string
    {
        $sources = array_values(array_unique(array_filter([$buy, $sell])));

        return implode(', ', $sources);
    }
}
