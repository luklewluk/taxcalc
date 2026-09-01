<?php

declare(strict_types=1);

namespace App\Import;

use App\Fifo\Trade;
use App\Import\Importer\BatchImporterInterface;
use App\Import\Importer\ImporterInterface;
use App\Import\Importer\TradeSourceImporterInterface;
use App\Model\ClosedPosition;
use App\Model\Dividend;
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
 * broker that issued them, matching keys mean different things (a ticker here,
 * an ISIN there), and one broker's statements say nothing about another's
 * holdings - so an IBKR upload and a DEGIRO upload are matched side by side and
 * both sets of positions come back.
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
         * @var array<class-string, array{BatchImporterInterface, list<CsvSource>}> $recordBatches
         */
        $recordBatches = [];

        foreach ($sources as $source) {
            $format = $this->formatDetector->detect($source);

            if (CsvFormat::Unknown === $format) {
                $result = $result->withMessages([ImportMessage::error(
                    $source->name,
                    'Nie rozpoznano formatu pliku. Obsługiwane formaty opisano na stronie kalkulatora - '
                    .'możesz też pobrać przykładowe pliki i porównać nagłówki.',
                )]);

                continue;
            }

            $importer = $this->importerFor($format);
            if (null === $importer) {
                $result = $result->withMessages([ImportMessage::error(
                    $source->name,
                    sprintf('Brak obsługi formatu "%s".', $format->label()),
                )]);

                continue;
            }

            if ($importer instanceof TradeSourceImporterInterface) {
                $extraction = $importer->extractTrades($source);

                $batch = $tradeBatches[$importer::class] ?? [$importer, []];
                $batch[1] = [...$batch[1], ...$extraction->trades];
                $tradeBatches[$importer::class] = $batch;

                $rawTrades += count($extraction->trades);
                $result = $result->withMessages($extraction->messages);

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

                continue;
            }

            if ($importer instanceof BatchImporterInterface) {
                $batch = $recordBatches[$importer::class] ?? [$importer, []];
                $batch[1][] = $source;
                $recordBatches[$importer::class] = $batch;

                continue;
            }

            $result = $result->merge($importer->import($source));
        }

        foreach ($recordBatches as [$batchImporter, $batchSources]) {
            $result = $result->merge($batchImporter->importMany($batchSources));
        }

        foreach ($tradeBatches as [$tradeImporter, $trades]) {
            [$trades, $tradeMessages] = $this->deduplicateTrades(
                $trades,
                $tradeImporter->tradeIdScope(),
                $tradeImporter->tradeIdLabel(),
            );

            $result = $result->merge($tradeImporter->matchTrades($trades))->withMessages($tradeMessages);
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
     * open position. Trades without a transaction ID are all kept, because
     * nothing distinguishes a genuine repeated fill from a duplicated row.
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
     * genuine identical executions in one export keep ordinals 1 and 2 and both
     * survive - collapsing those would halve a gain.
     *
     * @param list<Trade> $trades
     *
     * @return array{list<Trade>, list<ImportMessage>}
     */
    private function deduplicateTrades(array $trades, TradeIdScope $scope, string $idLabel): array
    {
        /** @var array<string, string> $seen key => the signature stored under it */
        $seen = [];
        /** @var array<string, string> $orderScope instrument, currency and side each order ID was seen on */
        $orderScope = [];
        /** @var array<string, array<string, true>> $orderFills distinct fills per order ID */
        $orderFills = [];

        $kept = [];
        $duplicates = 0;

        foreach ($trades as $trade) {
            if (null === $trade->externalId) {
                $kept[] = $trade;

                continue;
            }

            $signature = implode('|', [
                $trade->symbol,
                $trade->date->format('Y-m-d H:i:s'),
                (string) $trade->quantity,
                (string) $trade->grossAmount->value(),
                $trade->grossAmount->currency(),
            ]);

            if (TradeIdScope::Fill === $scope) {
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
            $scope = implode('|', [
                $trade->symbol,
                $trade->grossAmount->currency(),
                $trade->isBuy() ? 'K' : 'S',
            ]);

            if (($orderScope[$trade->externalId] ?? $scope) !== $scope) {
                return [[], [self::conflict($idLabel, $trade)]];
            }

            $orderScope[$trade->externalId] = $scope;
            $orderFills[$trade->externalId][$signature] = true;

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

        $split = count(array_filter($orderFills, static fn (array $fills): bool => count($fills) > 1));
        if ($split > 0) {
            $messages[] = ImportMessage::info(
                'Import',
                sprintf(
                    '%d zleceni(e/a) zostało wykonane w kilku transzach - każda transza jest rozliczana '
                    .'oddzielnie metodą FIFO.',
                    $split,
                ),
            );
        }

        return [$kept, $messages];
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

        [$dividends, $mergeMessages] = $this->mergeOverlappingDividends($dividends);
        $messages = [...$messages, ...$mergeMessages];

        if ($duplicates > 0) {
            $messages[] = ImportMessage::warning(
                'Import',
                sprintf('Pominięto %d duplikat(ów) - identyczne rekordy występowały w kilku plikach.', $duplicates),
            );
        }

        [$positions, $dividends, $capMessages] = $this->capRecords($positions, $dividends);
        $messages = [...$messages, ...$capMessages];

        $messages = [...$messages, ...$this->countryWarnings($dividends)];

        return new ImportResult($positions, $dividends, $messages);
    }

    /**
     * Collapses the same payment reported by two different IBKR exports.
     *
     * The activity export carries no country while the Dividend Detail export
     * does, so the two records are not byte-identical and the content
     * fingerprint keeps both - taxing the dividend twice. Records are merged
     * only when everything economically relevant matches (symbol, currency,
     * date, gross, withheld) and exactly one country is stated; the record that
     * names the country wins because it is strictly richer.
     *
     * Records that disagree on a stated country are both kept: that is a real
     * data conflict for the user to resolve, not a duplicate.
     *
     * @param list<Dividend> $dividends
     *
     * @return array{list<Dividend>, list<ImportMessage>}
     */
    private function mergeOverlappingDividends(array $dividends): array
    {
        /** @var array<string, list<int>> $groups */
        $groups = [];
        foreach ($dividends as $index => $dividend) {
            $groups[self::dividendPaymentKey($dividend)][] = $index;
        }

        $drop = [];
        $messages = [];

        foreach ($groups as $indexes) {
            if (count($indexes) < 2) {
                continue;
            }

            $withCountry = [];
            $withoutCountry = [];
            $countries = [];

            foreach ($indexes as $index) {
                $code = strtoupper(trim($dividends[$index]->countryCode));
                if ('' === $code) {
                    $withoutCountry[] = $index;

                    continue;
                }

                $withCountry[] = $index;
                $countries[$code] = true;
            }

            // Merge only when the country is unambiguous and something to merge.
            if (1 !== count($countries) || [] === $withoutCountry) {
                continue;
            }

            $keep = $dividends[$withCountry[0]];
            foreach ($withoutCountry as $index) {
                $drop[$index] = true;
            }

            $messages[] = ImportMessage::warning('Import', sprintf(
                'Scalono %d rekord(y) tej samej dywidendy %s z %s (%s %s) pochodzące z różnych zestawień; '
                .'zachowano wersję z krajem "%s". Sprawdź, czy to na pewno ta sama wypłata.',
                count($withoutCountry) + 1,
                $keep->name,
                $keep->date->format('Y-m-d'),
                (string) $keep->grossAmount->value(),
                $keep->currency,
                $keep->countryCode,
            ));
        }

        if ([] === $drop) {
            return [$dividends, $messages];
        }

        $kept = [];
        foreach ($dividends as $index => $dividend) {
            if (!isset($drop[$index])) {
                $kept[] = $dividend;
            }
        }

        return [$kept, $messages];
    }

    /**
     * Everything that identifies a payment, deliberately excluding the country.
     */
    private static function dividendPaymentKey(Dividend $dividend): string
    {
        return implode('|', [
            mb_strtoupper($dividend->name),
            $dividend->currency,
            $dividend->date->format('Y-m-d'),
            (string) $dividend->grossAmount->value()->toScale(4),
            (string) $dividend->withheldTax->value()->toScale(4),
        ]);
    }

    /**
     * @param list<ClosedPosition> $positions
     * @param list<Dividend>       $dividends
     *
     * @return array{list<ClosedPosition>, list<Dividend>, list<ImportMessage>}
     */
    private function capRecords(array $positions, array $dividends): array
    {
        $total = count($positions) + count($dividends);
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
