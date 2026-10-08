<?php

declare(strict_types=1);

namespace App\Import;

use App\Fifo\Trade;
use App\Import\Importer\BatchImporterInterface;
use App\Import\Importer\ImporterInterface;
use App\Import\Importer\TradeSourceImporterInterface;
use App\Model\ClosedPosition;
use App\Model\Dividend;
use App\Money\Amount;
use App\Money\Decimal;
use App\Tax\TaxRates;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Front door of the import layer: detects each uploaded file's format, routes
 * it to the importer that handles it, and merges the results.
 *
 * Trade files are handled in two phases. Every uploaded trade statement is read
 * first, the raw transactions are pooled and de-duplicated by the broker's
 * transaction ID, and only then does FIFO run - once per broker, over
 * everything that broker exported. Brokers export one statement per year, so a
 * position opened in 2024 and sold in 2025 only matches if both files are
 * considered together.
 *
 * The pool is per *format*, not global. Identifiers are only unique within the
 * broker that issued them, matching keys mean different things (ISIN and
 * currency here, the ISIN alone there), and one broker's statements say nothing
 * about another's holdings - so an IBKR upload and a DEGIRO upload are matched
 * side by side and both sets of positions come back.
 *
 * Dividend files can need the same treatment: a {@see BatchImporterInterface}
 * sees every uploaded file of its format at once, because the DEGIRO account
 * statement books a payment and the tax withheld on it as two rows that may
 * well arrive in different exports.
 *
 * Records are then de-duplicated so that re-uploading a statement - or uploading
 * two statements that overlap - cannot double anyone's tax bill.
 *
 * Everything happens in memory. Nothing is written to disk or to the session.
 */
final readonly class CsvImportService
{
    /**
     * Upper bound on normalized records produced by one import, applied before
     * any exchange-rate lookup or tax calculation so a pile of large uploads
     * cannot grow an unbounded model array.
     */
    public const int DEFAULT_MAX_RECORDS = 5000;

    /**
     * @param iterable<ImporterInterface> $importers
     */
    public function __construct(
        private FormatDetector $formatDetector,
        #[AutowireIterator(ImporterInterface::class)]
        private iterable $importers,
        private TaxRates $taxRates,
        private int $maxRecords = self::DEFAULT_MAX_RECORDS,
    ) {
    }

    /**
     * @param list<CsvSource> $sources
     */
    public function import(array $sources): ImportResult
    {
        $result = new ImportResult();

        /**
         * Raw transactions per trade format, keyed by importer class.
         *
         * @var array<class-string, array{TradeSourceImporterInterface, list<Trade>}> $tradeBatches
         */
        $tradeBatches = [];
        $rawTrades = 0;

        /**
         * Files whose importer needs the whole batch before it can decide what a
         * record is, keyed by importer class.
         *
         * @var array<class-string, array{BatchImporterInterface, list<CsvSource>, string}> $recordBatches
         */
        $recordBatches = [];

        foreach ($sources as $source) {
            $format = $this->formatDetector->detect($source);

            if (CsvFormat::Unknown === $format) {
                $result = $result->withMessages([ImportMessage::error(
                    $source->name,
                    'Nie rozpoznano formatu pliku. Obsługiwane formaty opisano na stronie kalkulatora - '
                    .'możesz też pobrać przykładowe pliki i porównać nagłówki.',
                )->forTab('attention')]);

                continue;
            }

            $importer = $this->importerFor($format);
            if (null === $importer) {
                $result = $result->withMessages([ImportMessage::error(
                    $source->name,
                    sprintf('Brak obsługi formatu "%s".', $format->label()),
                )->forTab('attention')]);

                continue;
            }

            if ($importer instanceof TradeSourceImporterInterface) {
                $extraction = $importer->extractTrades($source);

                $batch = $tradeBatches[$importer::class] ?? [$importer, []];
                $batch[1] = [...$batch[1], ...$extraction->trades];
                $tradeBatches[$importer::class] = $batch;

                $rawTrades += count($extraction->trades);
                $result = $result->withMessages(self::messagesForTab($extraction->messages, 'transactions'));

                if ($rawTrades > $this->maxRecords) {
                    return new ImportResult([], [], [...$result->messages, ImportMessage::error(
                        'Import',
                        sprintf(
                            'Przekroczono limit %d surowych transakcji. Nie wczytano żadnych danych; '
                            .'podziel zestawienia na mniejsze części.',
                            $this->maxRecords,
                        ),
                    )]);
                }

                // A statement can carry trades *and* records assembled across
                // files (the IBKR Activity Statement: trades plus dividends).
                if (!$importer instanceof BatchImporterInterface) {
                    continue;
                }
            }

            if ($importer instanceof BatchImporterInterface) {
                $batch = $recordBatches[$importer::class] ?? [$importer, [], self::targetTab($format)];
                $batch[1][] = $source;
                $recordBatches[$importer::class] = $batch;

                continue;
            }

            $imported = $importer->import($source);
            $result = $result->merge(self::resultWithMessageTarget($imported, self::targetTab($format)));
        }

        foreach ($recordBatches as [$batchImporter, $batchSources, $targetTab]) {
            $result = $result->merge(self::resultWithMessageTarget($batchImporter->importMany($batchSources), $targetTab));
        }

        foreach ($tradeBatches as [$tradeImporter, $trades]) {
            [$trades, $tradeMessages] = $this->deduplicateTrades(
                $trades,
                $tradeImporter->tradeIdScope(),
                $tradeImporter->tradeIdLabel(),
            );

            $matched = $tradeImporter->matchTrades($trades);
            $matched = self::resultWithMessageTarget(new ImportResult(
                $matched->positions,
                $matched->dividends,
                $matched->messages,
                $trades,
                $matched->fees,
            ), 'transactions');
            $result = $result->merge($matched)->withMessages(self::messagesForTab($tradeMessages, 'transactions'));
        }

        $result = $this->deduplicate($result);

        // Any import error makes the whole batch unusable. A tax calculation
        // from only the files/rows that happened to parse would look valid while
        // silently understating the result.
        if ([] !== $result->errors()) {
            return new ImportResult([], [], $result->messages);
        }

        return $result;
    }

    private static function targetTab(CsvFormat $format): string
    {
        return match ($format) {
            CsvFormat::DegiroTransactions => 'transactions',
            // A DEGIRO account statement can contain both dividends and fees,
            // so file-level errors stay on the attention list itself.
            // So can an IBKR Activity Statement: trades, dividends and withholding.
            CsvFormat::DegiroAccount, CsvFormat::IbkrActivityStatement, CsvFormat::Unknown => 'attention',
        };
    }

    /**
     * @param list<ImportMessage> $messages
     *
     * @return list<ImportMessage>
     */
    private static function messagesForTab(array $messages, string $targetTab): array
    {
        return array_map(
            static fn (ImportMessage $message): ImportMessage => null === $message->targetTab
                ? $message->forTab($targetTab)
                : $message,
            $messages,
        );
    }

    private static function resultWithMessageTarget(ImportResult $result, string $targetTab): ImportResult
    {
        return new ImportResult(
            $result->positions,
            $result->dividends,
            self::messagesForTab($result->messages, $targetTab),
            $result->trades,
            $result->fees,
        );
    }

    private function importerFor(CsvFormat $format): ?ImporterInterface
    {
        foreach ($this->importers as $importer) {
            if ($importer->supports($format)) {
                return $importer;
            }
        }

        return null;
    }

    /**
     * Drops raw transactions that appear in more than one uploaded statement.
     *
     * This has to happen *before* matching: a repeated buy would otherwise open a
     * second FIFO lot and both understate the matched cost and leave a phantom
     * open position. DEGIRO rows without a reported ID carry an explicitly
     * marked synthetic ID so overlaps can still be recognised without treating
     * that ID as a broker order during later aggregation.
     *
     * What counts as a repeat depends on what the identifier identifies
     * ({@see TradeIdScope}):
     *
     *  - {@see TradeIdScope::Fill} - one ID, one execution. The same ID with
     *    different content is a contradiction and stops the import.
     *  - {@see TradeIdScope::Order} - one ID, one order, possibly filled in
     *    several rows *and on several days*: a good-till-cancelled order is
     *    routinely worked over more than one session. Rows sharing an ID are
     *    those fills as long as they agree on the instrument, the currency and
     *    the direction; an ID reused for another instrument, another currency or
     *    the opposite side is a contradiction and stops the import.
     *
     * Identical rows are told apart by {@see Trade::$fillOrdinal}, the position
     * of the row inside the file it was read from. That is what makes this
     * independent of the file's *name*: the same export re-uploaded, renamed or
     * not, produces the same ordinals and its repeats drop out, while two
     * genuine identical rows without a reported ID keep ordinals 1 and 2 and
     * both survive. Rows with one reported DEGIRO Order ID and an exact shared
     * timestamp are subsequently aggregated into one logical transaction.
     *
     * @param list<Trade> $trades
     *
     * @return array{list<Trade>, list<ImportMessage>}
     */
    private function deduplicateTrades(array $trades, TradeIdScope $idScope, string $idLabel): array
    {
        /** @var array<string, string> $seen key => the signature stored under it */
        $seen = [];
        /** @var array<string, string> $orderScope instrument, currency and side each order ID was seen on */
        $orderScope = [];
        $kept = [];
        $duplicates = 0;

        foreach ($trades as $trade) {
            if (null === $trade->externalId) {
                $kept[] = $trade;

                continue;
            }

            $signature = implode('|', [
                $trade->broker,
                $trade->fifoPool,
                $trade->symbol,
                $trade->date->format('Y-m-d H:i:s'),
                (string) $trade->quantity,
                (string) $trade->grossAmount->value(),
                $trade->grossAmount->currency(),
                self::optionalAmountSignature($trade->unitPrice),
                self::optionalAmountSignature($trade->commission),
                self::optionalAmountSignature($trade->autoFx),
                $trade->kind->value,
                $trade->effect->value ?? '',
            ]);

            if (TradeIdScope::Fill === $idScope) {
                $key = $trade->externalId;

                if (isset($seen[$key])) {
                    if ($seen[$key] !== $signature) {
                        return [[], [self::conflict($idLabel, $trade)]];
                    }

                    ++$duplicates;

                    continue;
                }

                $seen[$key] = $signature;
                $kept[] = $trade;

                continue;
            }

            // Deliberately without the day: one order may be filled over
            // several sessions. What may *not* differ is which paper, in which
            // currency, and which way round.
            if ($trade->externalIdReported) {
                $orderIdentity = implode('|', [
                    $trade->broker,
                    $trade->fifoPool,
                    $trade->symbol,
                    $trade->grossAmount->currency(),
                    $trade->isBuy() ? 'K' : 'S',
                ]);

                if (($orderScope[$trade->externalId] ?? $orderIdentity) !== $orderIdentity) {
                    return [[], [self::conflict($idLabel, $trade)]];
                }

                $orderScope[$trade->externalId] = $orderIdentity;
            }

            $key = $trade->externalId.'|'.$signature.'|'.$trade->fillOrdinal;

            if (isset($seen[$key])) {
                ++$duplicates;

                continue;
            }

            $seen[$key] = $signature;
            $kept[] = $trade;
        }

        $messages = [];
        if ($duplicates > 0) {
            $messages[] = ImportMessage::info(
                'Import',
                sprintf(
                    'Pominięto %d powtórzon(ą/e) transakcj(ę/i) występując(ą/e) w kilku zestawieniach '
                    .'(rozpoznane po numerze %s).',
                    $duplicates,
                    $idLabel,
                ),
            );
        }

        $aggregated = 0;
        if (TradeIdScope::Order === $idScope) {
            [$kept, $aggregated, $aggregationMessages] = self::aggregateOrderFills($kept);
            $messages = [...$messages, ...$aggregationMessages];
            if ([] !== array_filter($aggregationMessages, static fn (ImportMessage $message): bool => MessageLevel::Error === $message->level)) {
                return [[], $messages];
            }
        }

        if ($aggregated > 0) {
            $messages[] = ImportMessage::info(
                'Import',
                sprintf(
                    'Zagregowano %d transz(e) tego samego zgłoszonego zlecenia wykonanych w dokładnie tym samym czasie; '
                    .'zsumowano liczbę sztuk i kwotę Total przed rozliczeniem FIFO.',
                    $aggregated,
                ),
            );
        }

        /** @var array<string, array<string, true>> $remainingOrderTimes */
        $remainingOrderTimes = [];
        foreach ($kept as $trade) {
            if ($trade->externalIdReported && null !== $trade->externalId) {
                $remainingOrderTimes[$trade->externalId][$trade->date->format('Y-m-d H:i:s')] = true;
            }
        }

        $split = count(array_filter($remainingOrderTimes, static fn (array $times): bool => count($times) > 1));
        if ($split > 0) {
            $messages[] = ImportMessage::info(
                'Import',
                sprintf(
                    '%d zleceni(e/a) zostało wykonane w kilku różnych terminach - wykonania z różnych chwil '
                    .'pozostają oddzielnymi transakcjami FIFO.',
                    $split,
                ),
            );
        }

        return [$kept, $messages];
    }

    /**
     * DEGIRO may emit several rows for one order at one timestamp. Once
     * overlapping exports have been deduplicated, those rows describe one
     * logical transaction: quantity and settled Total are additive. Synthetic
     * IDs never enter this path; their rows remain distinct by construction.
     *
     * @param list<Trade> $trades
     *
     * @return array{list<Trade>, int, list<ImportMessage>} aggregated trades, number of rows merged and errors
     */
    private static function aggregateOrderFills(array $trades): array
    {
        /** @var array<string, int> $groupIndexes */
        $groupIndexes = [];
        $aggregated = [];
        $merged = 0;
        $messages = [];

        foreach ($trades as $trade) {
            if (!$trade->externalIdReported || null === $trade->externalId) {
                $aggregated[] = $trade;

                continue;
            }

            $key = implode('|', [
                $trade->broker,
                $trade->fifoPool,
                $trade->externalId,
                $trade->symbol,
                $trade->isBuy() ? 'K' : 'S',
                $trade->grossAmount->currency(),
                $trade->date->format('Y-m-d H:i:s'),
            ]);

            if (!isset($groupIndexes[$key])) {
                $groupIndexes[$key] = count($aggregated);
                $aggregated[] = $trade;

                continue;
            }

            $index = $groupIndexes[$key];
            $first = $aggregated[$index];
            $sources = array_values(array_unique([$first->source, $trade->source]));
            if (null !== $first->unitPrice && null !== $trade->unitPrice
                && $first->unitPrice->currency() !== $trade->unitPrice->currency()) {
                $messages[] = ImportMessage::error('Import', sprintf(
                    'Nie można zagregować zlecenia %s dla %s: transze mają różne waluty ceny wykonania (%s i %s).',
                    $trade->externalId,
                    $trade->symbol,
                    $first->unitPrice->currency(),
                    $trade->unitPrice->currency(),
                ));

                continue;
            }

            $unitPrice = self::weightedUnitPrice(
                $first->unitPrice,
                $first->quantity->abs(),
                $trade->unitPrice,
                $trade->quantity->abs(),
            );

            $aggregated[$index] = new Trade(
                $first->symbol,
                $first->date,
                $first->quantity->plus($trade->quantity),
                $first->grossAmount->plus($trade->grossAmount),
                $first->externalId,
                implode(', ', array_filter($sources, static fn (string $source): bool => '' !== $source)),
                $first->instrument,
                $first->fillOrdinal,
                true,
                $unitPrice,
                $first->executionVenue,
                $first->broker,
                self::sumOptional($first->commission, $trade->commission),
                self::sumOptional($first->autoFx, $trade->autoFx),
                $first->id(),
                $first->fifoPool,
                $first->kind,
                $first->effect,
            );
            ++$merged;
        }

        return [array_values($aggregated), $merged, $messages];
    }

    private static function weightedUnitPrice(
        ?Amount $left,
        Decimal $leftQuantity,
        ?Amount $right,
        Decimal $rightQuantity,
    ): ?Amount {
        // A partial price would suggest that it describes the whole order. If
        // even one fill lacks it, keep the aggregated audit field explicitly
        // unknown while preserving all taxable settled amounts.
        if (null === $left || null === $right) {
            return null;
        }

        $quantity = $leftQuantity->plus($rightQuantity);
        $weighted = $left->value()->multipliedBy($leftQuantity)
            ->plus($right->value()->multipliedBy($rightQuantity));
        $scale = max(8, $left->value()->scale(), $right->value()->scale());

        return Amount::fromDecimal($weighted->dividedBy($quantity, $scale), $left->currency());
    }

    private static function conflict(string $idLabel, Trade $trade): ImportMessage
    {
        return ImportMessage::error(
            'Import',
            sprintf(
                'Konflikt %s "%s" dla %s: ten sam identyfikator występuje przy innym instrumencie, '
                .'innej walucie lub po przeciwnej stronie transakcji. Import przerwano w całości.',
                $idLabel,
                $trade->externalId ?? '',
                $trade->symbol,
            ),
        );
    }

    private function deduplicate(ImportResult $result): ImportResult
    {
        $messages = $result->messages;
        $duplicates = 0;

        $seen = [];
        $positions = [];
        foreach ($result->positions as $position) {
            $fingerprint = $position->fingerprint();
            if (isset($seen[$fingerprint])) {
                ++$duplicates;

                continue;
            }

            $seen[$fingerprint] = true;
            $positions[] = $position;
        }

        $dividends = [];
        foreach ($result->dividends as $dividend) {
            $fingerprint = $dividend->fingerprint();
            if (isset($seen[$fingerprint])) {
                ++$duplicates;

                continue;
            }

            $seen[$fingerprint] = true;
            $dividends[] = $dividend;
        }

        if ($duplicates > 0) {
            $messages[] = ImportMessage::warning(
                'Import',
                sprintf('Pominięto %d duplikat(ów) - identyczne rekordy występowały w kilku plikach.', $duplicates),
            );
        }

        [$positions, $dividends, $capMessages] = $this->capRecords(
            $positions,
            $dividends,
            count($result->trades),
            count($result->fees),
        );
        $messages = [...$messages, ...$capMessages];

        $messages = [...$messages, ...$this->countryWarnings($dividends)];

        return new ImportResult(
            $positions,
            $dividends,
            $messages,
            $result->trades,
            $result->fees,
        );
    }

    private static function sumOptional(?Amount $left, ?Amount $right): ?Amount
    {
        if (null === $left || null === $right) {
            return null;
        }

        return $left->plus($right);
    }

    private static function optionalAmountSignature(?Amount $amount): string
    {
        return null === $amount
            ? 'null'
            : $amount->currency().':'.(string) $amount->value();
    }

    /**
     * @param list<ClosedPosition> $positions
     * @param list<Dividend>       $dividends
     *
     * @return array{list<ClosedPosition>, list<Dividend>, list<ImportMessage>}
     */
    private function capRecords(
        array $positions,
        array $dividends,
        int $tradeCount,
        int $feeCount,
    ): array
    {
        // Positions are derived from the logical trades, so counting both would
        // halve the effective limit.
        $total = $tradeCount + count($dividends) + $feeCount;
        if ($total <= $this->maxRecords) {
            return [$positions, $dividends, []];
        }

        return [[], [], [ImportMessage::error(
            'Import',
            sprintf(
                'Przekroczono limit %d rekordów na jeden import (wczytano %d). Nie wczytano żadnych danych - '
                .'podziel dane na mniejsze części, np. po jednym roku podatkowym, aby uniknąć niepełnego wyniku.',
                $this->maxRecords,
                $total,
            ),
        )]];
    }

    /**
     * @param list<Dividend> $dividends
     *
     * @return list<ImportMessage>
     */
    private function countryWarnings(array $dividends): array
    {
        $unknown = [];

        foreach ($dividends as $dividend) {
            $code = strtoupper($dividend->countryCode);
            if ('' !== $code && !$this->taxRates->isKnownCountry($code)) {
                $unknown[$code] = true;
            }
        }

        $messages = [];
        foreach (array_keys($unknown) as $code) {
            $messages[] = ImportMessage::warning(
                'Import',
                sprintf('Kod kraju "%s" nie ma skonfigurowanej stawki umownej - zweryfikuj go przed obliczeniem.', $code),
            );
        }

        return $messages;
    }
}
