<?php

declare(strict_types=1);

namespace App\Web;

use App\Exception\InvalidRecordException;
use App\Fifo\FifoMatch;
use App\Fifo\FifoMatcher;
use App\Fifo\FifoViolationKind;
use App\Fifo\InstrumentDetails;
use App\Fifo\LotAssignments;
use App\Fifo\UnmatchedSell;
use App\Fifo\Trade;
use App\Import\Degiro\ExchangeCountry;
use App\Model\ClosedPosition;
use App\Settlement\SettlementCycle;
use App\Settlement\SettlementDateResolver;
use DateTimeImmutable;

/** Re-runs FIFO over the editable logical transactions on every submission. */
final readonly class WorkbenchCalculator
{
    public function __construct(
        private FifoMatcher $fifoMatcher,
        private SettlementDateResolver $settlementDates = new SettlementDateResolver(),
    ) {
    }

    /**
     * @param list<Trade> $trades
     */
    public function settle(
        array $trades,
        LotAssignments $assignments = new LotAssignments(),
        SettlementCycle $cycle = SettlementCycle::TradeDate,
    ): SettlementResult {
        $fifo = $this->fifoMatcher->match($trades, $assignments);
        $positions = [];
        $matchPositions = [];
        $errors = [];
        $diagnostics = [];

        foreach ($fifo->matches as $index => $match) {
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
                $position = $this->settled(ClosedPosition::fromMatch(
                    $match,
                    $name,
                    $country,
                    self::source($match->buySource, $match->sellSource),
                ), $match, $cycle);
                $positions[] = $position;
                $matchPositions[$index] = $position;
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
            // position, a missing open/close, stocks and options in one queue)
            // - or a named lot that cannot be honoured, fixed where lots are
            // chosen, on the FIFO tab.
            $message = $violation->describe();
            $errors[] = $message;
            $diagnostics[] = Diagnostic::blocking(
                $code,
                $message,
                $violation->kind->isLotAssignment() ? 'fifo' : 'transactions',
                $violation->tradeId ?: null,
            );
        }

        return new SettlementResult(
            $positions,
            $fifo->matches,
            $errors,
            $diagnostics,
            $matchPositions,
            $fifo->openPositions,
            $fifo->unmatchedSells,
            $fifo->violations,
        );
    }

    private static function source(string $buy, string $sell): string
    {
        $sources = array_values(array_unique(array_filter([$buy, $sell])));

        return implode(', ', $sources);
    }

    /**
     * The days the legs settle under the chosen cycle. A leg worth nothing - an
     * option that expired or was assigned - is not a trade that settles, so it
     * keeps its own day.
     */
    private function settled(ClosedPosition $position, FifoMatch $match, SettlementCycle $cycle): ClosedPosition
    {
        if (SettlementCycle::TradeDate === $cycle) {
            return $position;
        }

        $leg = function (DateTimeImmutable $date, ?InstrumentDetails $instrument, bool $zero) use ($position, $match, $cycle): ?DateTimeImmutable {
            return $zero ? null : $this->settlementDates->settle($date, self::market($instrument, $position->countryCode), $match->kind, $cycle);
        };

        return $position->withSettlement(
            $leg($position->buyDate, $match->buyInstrument, $position->buyAmount->isZero()),
            $leg($position->sellDate, $match->sellInstrument, $position->sellAmount->isZero()),
        );
    }

    /**
     * The market a leg was made on: its venue's country, else the country on
     * the row - which a user fills before anything is settled anyway.
     */
    private static function market(?InstrumentDetails $instrument, string $fallback): string
    {
        $venue = '' === ($instrument->exchangeCode ?? '') ? '' : ExchangeCountry::country($instrument->exchangeCode ?? '');
        if ('' !== $venue) {
            return $venue;
        }

        return '' !== ($instrument->countryCode ?? '') ? (string) $instrument?->countryCode : $fallback;
    }
}
