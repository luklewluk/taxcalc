<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import\Importer;

use App\Fifo\FifoMatcher;
use App\Fifo\InstrumentKind;
use App\Fifo\PositionDirection;
use App\Fifo\PositionEffect;
use App\Import\CsvSource;
use App\Import\Importer\IbkrActivityStatementImporter;
use App\Import\ImportMessage;
use App\Import\ImportResult;
use App\Import\MessageLevel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IbkrActivityStatementImporter::class)]
final class IbkrActivityStatementImporterTest extends TestCase
{
    private const string PREAMBLE = "Statement,Header,Field Name,Field Value\n"
        ."Statement,Data,Title,Activity Statement\n"
        ."Account Information,Header,Field Name,Field Value\n"
        ."Account Information,Data,Name,Jan Przykładowy\n"
        ."Account Information,Data,Account,UXXXXXXXX\n";

    private const string TRADES_HEADER = 'Trades,Header,DataDiscriminator,Asset Category,Currency,Symbol,Date/Time,'
        .'Quantity,T. Price,C. Price,Proceeds,Comm/Fee,Basis,Realized P/L,MTM P/L,Code';

    private const string FOREX_HEADER = 'Trades,Header,DataDiscriminator,Asset Category,Currency,Symbol,Date/Time,'
        .'Quantity,T. Price,,Proceeds,Comm in USD,,,MTM in USD,Code';

    private const string FII_HEADER = 'Financial Instrument Information,Header,Asset Category,Symbol,Description,'
        .'Conid,Security ID,Underlying,Listing Exch,Multiplier,Type,Code';

    private const string FII_AAA = 'Financial Instrument Information,Data,Stocks,AAA,ALFA CORP,1001,US000ALFA001,AAA,NASDAQ,1,COMMON,';

    private const string FII_BBB = 'Financial Instrument Information,Data,Stocks,BBB,BETA ETF,1002,IE000BETA002,BBB,LSEETF,1,ETF,';

    private const string FII_OPTIONS_HEADER = 'Financial Instrument Information,Header,Asset Category,Symbol,Description,'
        .'Conid,Underlying,Listing Exch,Multiplier,Expiry,Delivery Month,Type,Strike,Code';

    private const string PUT = 'AAA 16JAN26 50 P';

    // As IBKR prints it: the OCC code as the symbol, the trades' symbol as the description.
    private const string FII_PUT = 'Financial Instrument Information,Data,Equity and Index Options,AAA   260116P00050000,'
        .'AAA 16JAN26 50 P,2001,AAA,CBOE,100,2026-01-16,2026-01,P,50,';

    private const string OPTIONS = 'Equity and Index Options';

    private const string DIVIDENDS_HEADER = 'Dividends,Header,Currency,Date,Description,Amount';

    private const string WITHHOLDING_HEADER = 'Withholding Tax,Header,Currency,Date,Description,Amount,Code';

    public function testABuyAndASellAreMatchedFromTheSettledCashAndTheSellCommissionIsKeptApart(): void
    {
        $result = $this->import(self::statement([
            self::trade('AAA', '2025-03-03, 10:00:00', '10', '100', '-1000', '-1'),
            self::trade('AAA', '2026-02-02, 15:30:00', '-10', '120', '1200', '-1.5', 'C'),
        ]));

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->positions);

        $position = $result->positions[0];
        self::assertSame('ALFA CORP', $position->name);
        self::assertSame('AAA', $position->symbol);
        self::assertSame('IBKR', $position->broker);
        self::assertSame('USD', $position->currency);
        self::assertSame('2025-03-03 00:00:00', $position->buyDate->format('Y-m-d H:i:s'));
        self::assertSame('2026-02-02 00:00:00', $position->sellDate->format('Y-m-d H:i:s'));
        self::assertSame('1001.00', (string) $position->buyAmount->value());
        self::assertSame('1198.50', (string) $position->sellAmount->value());
        self::assertSame('1.00', (string) $position->buyCommission?->value());
        self::assertSame('1.50', (string) $position->sellCommission?->value());
        self::assertSame('120', (string) $position->sellUnitPrice?->value());
    }

    public function testTheCountryIsProposedFromTheListingExchange(): void
    {
        $result = $this->import(self::statement([
            self::trade('AAA', '2025-03-03, 10:00:00', '1', '100', '-100', '-1'),
            self::trade('AAA', '2025-04-03, 10:00:00', '-1', '110', '110', '-1', 'C'),
            self::trade('BBB', '2025-03-03, 11:00:00', '1', '50', '-50', '-1'),
            self::trade('BBB', '2025-04-03, 11:00:00', '-1', '55', '55', '-1', 'C'),
        ]));

        $countries = [];
        foreach ($result->positions as $position) {
            $countries[$position->symbol] = $position->countryCode;
        }

        self::assertSame(['AAA' => 'US', 'BBB' => 'GB'], $countries);
    }

    public function testTheTradeCarriesTheExchangeAsAMicSoTheCountrySettingCanReReadIt(): void
    {
        $importer = new IbkrActivityStatementImporter(new FifoMatcher());
        $extraction = $importer->extractTrades(new CsvSource('as.csv', self::statement([
            self::trade('BBB', '2025-03-03, 11:00:00', '1', '50', '-50', '-1'),
        ])));

        self::assertCount(1, $extraction->trades);
        $trade = $extraction->trades[0];
        self::assertSame('XLON', $trade->instrument?->exchangeCode);
        self::assertSame('GB', $trade->instrument->countryCode);
        self::assertSame('BETA ETF', $trade->instrument->displayName);
        self::assertSame('IE000BETA002@USD', $trade->fifoPool);
        self::assertSame('2025-03-03 11:00:00', $trade->date->format('Y-m-d H:i:s'));
        self::assertFalse($trade->externalIdReported);
        self::assertStringStartsWith('auto:', (string) $trade->externalId);
    }

    public function testAnUnknownExchangeLeavesTheCountryBlankAndSaysWhichCode(): void
    {
        $result = $this->import(self::statement([
            self::trade('AAA', '2025-03-03, 10:00:00', '1', '100', '-100', '-1'),
            self::trade('AAA', '2025-04-03, 10:00:00', '-1', '110', '110', '-1', 'C'),
        ], [str_replace('NASDAQ', 'MOONEX', self::FII_AAA)]));

        self::assertSame([], $result->errors());
        self::assertSame('', $result->positions[0]->countryCode);
        self::assertStringContainsString('MOONEX', self::joined($result->warnings()));
    }

    public function testATickerChangeListsBothSymbolsAndKeepsOneQueue(): void
    {
        // IBKR prints every symbol a contract has carried in one field.
        $result = $this->import(self::statement([
            self::trade('AAAX', '2025-03-03, 10:00:00', '2', '100', '-200', '-1'),
            self::trade('AAA', '2026-02-02, 15:30:00', '-2', '120', '240', '-1', 'C'),
        ], [str_replace(',AAA,ALFA CORP,', ',"AAA, AAAX",ALFA CORP,', self::FII_AAA)]));

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->positions);
        self::assertSame('US', $result->positions[0]->countryCode);
        self::assertSame([], array_filter(
            $result->warnings(),
            static fn (string $warning): bool => str_contains($warning, 'nie podaje'),
        ));
    }

    public function testFifoSpansTwoYearlyStatements(): void
    {
        $importer = new IbkrActivityStatementImporter(new FifoMatcher());
        $older = $importer->extractTrades(new CsvSource('2025.csv', self::statement([
            self::trade('AAA', '2025-03-03, 10:00:00', '10', '100', '-1000', '-1'),
        ])));
        $newer = $importer->extractTrades(new CsvSource('2026.csv', self::statement([
            self::trade('AAA', '2026-02-02, 15:30:00', '-4', '120', '480', '-1', 'C'),
        ])));

        $result = $importer->matchTrades([...$newer->trades, ...$older->trades]);

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->positions);
        // A round lot prorates to the cent, not to whole dollars.
        self::assertSame('400.40', (string) $result->positions[0]->buyAmount->value());
    }

    public function testASaleWithoutAPurchaseIsLeftOutWithAWarningAndTheRestImports(): void
    {
        $result = $this->import(self::statement([
            self::trade('AAA', '2026-02-02, 15:30:00', '-4', '120', '480', '-1', 'C'),
            self::trade('BBB', '2026-03-03, 10:00:00', '1', '50', '-50', '-1'),
            self::trade('BBB', '2026-04-03, 10:00:00', '-1', '55', '55', '-1', 'C'),
        ]));

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->positions, 'The covered sale still settles.');
        self::assertSame('BBB', $result->positions[0]->symbol);

        $review = self::ofLevel($result->messages, MessageLevel::Review);
        $unmatched = array_values(array_filter($review, static fn (ImportMessage $m): bool => 'fifo.unmatched_sell' === $m->code));
        self::assertCount(1, $unmatched);
        self::assertStringContainsString('AAA', $unmatched[0]->message);
        self::assertStringContainsString('wcześniejszy rok', $unmatched[0]->message);
        self::assertSame('transactions', $unmatched[0]->targetTab);
    }

    public function testIdenticalRowsInOneFileAreTwoTrades(): void
    {
        $row = self::trade('AAA', '2025-03-03, 10:00:00', '1', '100', '-100', '-1');
        $importer = new IbkrActivityStatementImporter(new FifoMatcher());
        $extraction = $importer->extractTrades(new CsvSource('as.csv', self::statement([$row, $row])));

        self::assertCount(2, $extraction->trades);
        self::assertNotSame($extraction->trades[0]->externalId, $extraction->trades[1]->externalId);
        self::assertSame([1, 2], [$extraction->trades[0]->fillOrdinal, $extraction->trades[1]->fillOrdinal]);
    }

    public function testTheSameRowInTwoFilesKeepsOneIdentity(): void
    {
        $row = self::trade('AAA', '2025-03-03, 10:00:00', '1', '100', '-100', '-1');
        $importer = new IbkrActivityStatementImporter(new FifoMatcher());

        $first = $importer->extractTrades(new CsvSource('a.csv', self::statement([$row])));
        $second = $importer->extractTrades(new CsvSource('b.csv', self::statement([$row])));

        self::assertSame($first->trades[0]->externalId, $second->trades[0]->externalId);
    }

    public function testForexConversionsAreNotTrades(): void
    {
        $content = self::statement([
            self::trade('AAA', '2025-03-03, 10:00:00', '1', '100', '-100', '-1'),
        ], more: [
            self::FOREX_HEADER,
            'Trades,Data,Order,Forex,PLN,USD.PLN,"2025-01-16, 06:19:31",17,3.63542,,-61.80214,0,,,0.013064,AFx',
        ]);

        $extraction = (new IbkrActivityStatementImporter(new FifoMatcher()))->extractTrades(new CsvSource('as.csv', $content));

        self::assertCount(1, $extraction->trades);
        self::assertSame([], self::ofLevel($extraction->messages, MessageLevel::Error));
        self::assertSame([], self::ofLevel($extraction->messages, MessageLevel::Review));
    }

    public function testUnsupportedAssetClassesAreSkippedLoudly(): void
    {
        $extraction = (new IbkrActivityStatementImporter(new FifoMatcher()))->extractTrades(new CsvSource('as.csv', self::statement([
            self::trade('ESZ6', '2025-03-03, 10:00:00', '1', '5000', '-250000', '-2', 'O', category: 'Futures'),
        ])));

        self::assertSame([], $extraction->trades);
        $review = self::joined(self::ofLevel($extraction->messages, MessageLevel::Review));
        self::assertStringContainsString('Futures', $review);
        self::assertStringContainsString('dolicz', $review);
    }

    public function testAProceedsSignThatContradictsTheSideIsAnError(): void
    {
        $result = $this->import(self::statement([
            self::trade('AAA', '2025-03-03, 10:00:00', '1', '100', '100', '-1'),
        ]));

        self::assertNotSame([], $result->errors());
    }

    public function testSharesMovingWithoutCashStopTheImport(): void
    {
        $result = $this->import(self::statement([
            self::trade('AAA', '2025-03-03, 10:00:00', '1', '0', '0', '0'),
        ]));

        self::assertStringContainsString('operację korporacyjną', self::joined($result->errors()));
    }

    public function testACancelledOrCorrectedTradeStopsTheImport(): void
    {
        foreach (['Ca', 'O;Co'] as $code) {
            $result = $this->import(self::statement([
                self::trade('AAA', '2025-03-03, 10:00:00', '1', '100', '-100', '-1', $code),
            ]));

            self::assertNotSame([], $result->errors(), $code);
        }
    }

    public function testACorporateActionSectionStopsTheImport(): void
    {
        $result = $this->import(self::statement([
            self::trade('AAA', '2025-03-03, 10:00:00', '1', '100', '-100', '-1'),
        ], more: [
            'Corporate Actions,Header,Asset Category,Currency,Report Date,Date/Time,Description,Quantity,Proceeds,Value,Realized P/L,Code',
            'Corporate Actions,Data,Stocks,USD,2025-06-01,"2025-06-01, 20:25:00",AAA(US000ALFA001) Split 4 for 1 (AAA),3,0,0,0,',
        ]));

        self::assertStringContainsString('AAA', self::joined($result->errors()));
    }

    public function testAStockFromAnOptionAssignmentIsBoughtAtTheStrikeAndExplained(): void
    {
        $result = $this->import(self::statement([
            self::trade('AAA', '2025-03-21, 16:20:00', '100', '50', '-5000', '0', 'A;O'),
        ]));

        self::assertSame([], $result->errors());
        $review = self::joined(self::ofLevel($result->messages, MessageLevel::Review));
        self::assertStringContainsString('AAA', $review);
        self::assertStringContainsString('premia', $review);
    }

    public function testABuyAndASellAtTheSameSecondAreRefused(): void
    {
        $result = $this->import(self::statement([
            self::trade('AAA', '2025-03-03, 10:00:00', '2', '100', '-200', '-1'),
            self::trade('AAA', '2025-03-03, 10:00:00', '-1', '100', '100', '-1', 'C'),
        ]));

        self::assertSame([], $result->positions);
        self::assertNotSame([], $result->errors());
    }

    public function testADividendAndItsWithholdingBecomeOneRecordWithTheCountryFromTheIsin(): void
    {
        $result = $this->import(self::statement([], more: [
            self::DIVIDENDS_HEADER,
            'Dividends,Data,USD,2026-09-30,AAA(US000ALFA001) Cash Dividend USD 0.65 per Share (Ordinary Dividend),6.5',
            'Dividends,Data,Total,,,6.5',
            self::WITHHOLDING_HEADER,
            'Withholding Tax,Data,USD,2026-07-07,Withholding @ 20% on Credit Interest for Jun-2026,-0.04,',
            'Withholding Tax,Data,USD,2026-09-30,AAA(US000ALFA001) Cash Dividend USD 0.65 per Share - US Tax,-0.98,',
            'Withholding Tax,Data,Total,,,-1.02,',
        ]));

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->dividends);

        $dividend = $result->dividends[0];
        self::assertSame('AAA', $dividend->name);
        self::assertSame('US', $dividend->countryCode);
        self::assertSame('USD', $dividend->currency);
        self::assertSame('2026-09-30', $dividend->date->format('Y-m-d'));
        self::assertSame('6.5', (string) $dividend->grossAmount->value());
        self::assertSame('0.98', (string) $dividend->withheldTax->value());
    }

    public function testAPaymentInLieuJoinsTheDividendOfTheSameDay(): void
    {
        $result = $this->import(self::statement([], more: [
            self::DIVIDENDS_HEADER,
            'Dividends,Data,USD,2026-09-30,AAA(US000ALFA001) Cash Dividend USD 0.65 per Share (Ordinary Dividend),6.5',
            'Payment In Lieu Of Dividends,Header,Currency,Date,Description,Amount',
            'Payment In Lieu Of Dividends,Data,USD,2026-09-30,AAA(US000ALFA001) Payment in Lieu of Dividend (Ordinary Dividend),1.3',
        ]));

        self::assertCount(1, $result->dividends);
        self::assertSame('7.8', (string) $result->dividends[0]->grossAmount->value());
    }

    public function testAWithholdingInAnotherStatementIsStillMatched(): void
    {
        $importer = new IbkrActivityStatementImporter(new FifoMatcher());
        $result = $importer->importMany([
            new CsvSource('a.csv', self::statement([], more: [
                self::DIVIDENDS_HEADER,
                'Dividends,Data,USD,2026-09-30,AAA(US000ALFA001) Cash Dividend USD 0.65 per Share (Ordinary Dividend),6.5',
            ])),
            new CsvSource('b.csv', self::statement([], more: [
                self::WITHHOLDING_HEADER,
                'Withholding Tax,Data,USD,2026-09-30,AAA(US000ALFA001) Cash Dividend USD 0.65 per Share - US Tax,-0.98,',
            ])),
        ]);

        self::assertCount(1, $result->dividends);
        self::assertSame('0.98', (string) $result->dividends[0]->withheldTax->value());
    }

    public function testTheSamePaymentInTwoOverlappingStatementsIsCountedOnce(): void
    {
        $statement = self::statement([], more: [
            self::DIVIDENDS_HEADER,
            'Dividends,Data,USD,2026-09-30,AAA(US000ALFA001) Cash Dividend USD 0.65 per Share (Ordinary Dividend),6.5',
            self::WITHHOLDING_HEADER,
            'Withholding Tax,Data,USD,2026-09-30,AAA(US000ALFA001) Cash Dividend USD 0.65 per Share - US Tax,-0.98,',
        ]);

        $result = (new IbkrActivityStatementImporter(new FifoMatcher()))->importMany([
            new CsvSource('annual.csv', $statement),
            new CsvSource('annual (1).csv', $statement),
        ]);

        self::assertCount(1, $result->dividends);
        self::assertSame('6.5', (string) $result->dividends[0]->grossAmount->value());
        self::assertSame('0.98', (string) $result->dividends[0]->withheldTax->value());
    }

    public function testTwoIdenticalPaymentRowsInOneStatementAreBothKept(): void
    {
        $row = 'Dividends,Data,USD,2026-09-30,AAA(US000ALFA001) Cash Dividend USD 0.65 per Share (Ordinary Dividend),6.5';
        $result = $this->import(self::statement([], more: [self::DIVIDENDS_HEADER, $row, $row]));

        self::assertSame('13.0', (string) $result->dividends[0]->grossAmount->value());
    }

    public function testAReversalAndARepostInTheSameYearLeaveOnlyTheRepost(): void
    {
        $result = $this->import(self::statement([], more: [
            self::DIVIDENDS_HEADER,
            'Dividends,Data,USD,2025-03-03,AAA(US000ALFA001) Cash Dividend USD 1.00 per Share (Ordinary Dividend),100',
            'Dividends,Data,USD,2025-06-02,AAA(US000ALFA001) Cash Dividend USD 1.00 per Share (Ordinary Dividend),-100',
            'Dividends,Data,USD,2025-06-03,AAA(US000ALFA001) Cash Dividend USD 0.80 per Share (Ordinary Dividend),80',
        ]));

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->dividends);
        self::assertSame('80', (string) $result->dividends[0]->grossAmount->value());
        self::assertSame('2025-06-03', $result->dividends[0]->date->format('Y-m-d'));
        self::assertStringContainsString('2025-03-03', self::joined(self::ofLevel($result->messages, MessageLevel::Review)));
    }

    public function testAWithholdingRefundInTheSameYearReducesTheCredit(): void
    {
        $result = $this->import(self::statement([], more: [
            self::DIVIDENDS_HEADER,
            'Dividends,Data,USD,2025-03-03,AAA(US000ALFA001) Cash Dividend USD 1.00 per Share (Ordinary Dividend),100',
            self::WITHHOLDING_HEADER,
            'Withholding Tax,Data,USD,2025-03-03,AAA(US000ALFA001) Cash Dividend USD 1.00 per Share - US Tax,-30,',
            'Withholding Tax,Data,USD,2025-05-10,AAA(US000ALFA001) Cash Dividend USD 1.00 per Share - US Tax,15,',
        ]));

        self::assertCount(1, $result->dividends);
        self::assertSame('100', (string) $result->dividends[0]->grossAmount->value());
        self::assertSame('15', (string) $result->dividends[0]->withheldTax->value());
        self::assertStringContainsString('skorygowano', self::joined(self::ofLevel($result->messages, MessageLevel::Review)));
    }

    public function testACorrectionBookedInTheNextYearDoesNotReachBack(): void
    {
        $result = $this->import(self::statement([], more: [
            self::DIVIDENDS_HEADER,
            'Dividends,Data,USD,2025-12-15,AAA(US000ALFA001) Cash Dividend USD 1.00 per Share (Ordinary Dividend),100',
            'Dividends,Data,USD,2026-02-03,AAA(US000ALFA001) Cash Dividend USD 1.00 per Share (Ordinary Dividend),-100',
        ]));

        self::assertCount(1, $result->dividends);
        self::assertSame('2025-12-15', $result->dividends[0]->date->format('Y-m-d'));
        self::assertStringContainsString('koryguje rok', self::joined(self::ofLevel($result->messages, MessageLevel::Review)));
    }

    public function testAReversedPaymentIsSkippedAndReported(): void
    {
        $result = $this->import(self::statement([], more: [
            self::DIVIDENDS_HEADER,
            'Dividends,Data,USD,2026-02-03,AAA(US000ALFA001) Cash Dividend USD 0.65 per Share (Ordinary Dividend),-6.5',
        ]));

        self::assertSame([], $result->errors());
        self::assertSame([], $result->dividends);
        self::assertStringContainsString('AAA', self::joined(self::ofLevel($result->messages, MessageLevel::Review)));
    }

    public function testAWithholdingWithoutItsDividendIsReportedNotDropped(): void
    {
        $result = $this->import(self::statement([], more: [
            self::WITHHOLDING_HEADER,
            'Withholding Tax,Data,USD,2026-02-10,AAA(US000ALFA001) Cash Dividend USD 0.65 per Share - US Tax,0.33,',
        ]));

        self::assertSame([], $result->errors());
        self::assertStringContainsString('AAA', self::joined(self::ofLevel($result->messages, MessageLevel::Review)));
    }

    public function testADividendRowThatNamesNoInstrumentIsAnError(): void
    {
        $result = $this->import(self::statement([], more: [
            self::DIVIDENDS_HEADER,
            'Dividends,Data,USD,2026-09-30,Something without an ISIN,6.5',
        ]));

        self::assertNotSame([], $result->errors());
    }

    public function testTheHolderNeverReachesARecord(): void
    {
        $result = $this->import(self::statement([
            self::trade('AAA', '2025-03-03, 10:00:00', '1', '100', '-100', '-1'),
            self::trade('AAA', '2025-04-03, 10:00:00', '-1', '110', '110', '-1', 'C'),
        ], more: [
            self::DIVIDENDS_HEADER,
            'Dividends,Data,USD,2026-09-30,AAA(US000ALFA001) Cash Dividend USD 0.65 per Share (Ordinary Dividend),6.5',
        ]));

        $serialized = serialize([$result->positions, $result->dividends, $result->trades]);
        self::assertStringNotContainsString('UXXXXXXXX', $serialized);
        self::assertStringNotContainsString('Przykładowy', $serialized);
    }

    public function testAnUnreadableFileIsReportedOnce(): void
    {
        $result = $this->import(self::PREAMBLE);

        self::assertSame([], $result->positions);
        self::assertSame([], $result->dividends);
    }

    public function testAWrittenPutThatExpiresSettlesInTheExpiryYear(): void
    {
        $result = $this->import(self::withOptions([
            self::option('2025-12-15, 15:00:00', '-1', '0.99', '99', '-1', 'O'),
            self::option('2026-01-16, 16:20:00', '1', '0', '0', '0', 'C;Ep'),
        ]));

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->positions);

        $position = $result->positions[0];
        self::assertSame(InstrumentKind::Option, $position->kind);
        self::assertSame(PositionDirection::Short, $position->direction);
        self::assertSame(self::PUT, $position->symbol);
        self::assertSame('US', $position->countryCode);
        self::assertSame('98.00', (string) $position->sellAmount->value());
        self::assertSame('1.00', (string) $position->sellCommission?->value());
        self::assertSame('0.00', (string) $position->buyAmount->value());
        self::assertSame(2026, $position->taxYear());
    }

    public function testTheOpenCloseCodeBecomesTheDeclaredEffect(): void
    {
        $extraction = (new IbkrActivityStatementImporter(new FifoMatcher()))->extractTrades(new CsvSource('as.csv', self::withOptions([
            self::option('2026-03-02, 10:00:00', '1', '1.2', '-120', '-0.65', 'O'),
            self::option('2026-03-09, 10:00:00', '-2', '2', '200', '-1.30', 'C;O'),
            self::option('2026-03-20, 10:00:00', '1', '0.5', '-50', '-0.65', 'C;P'),
        ])));

        self::assertSame(
            [PositionEffect::Open, PositionEffect::CloseThenOpen, PositionEffect::Close],
            array_map(static fn ($trade): ?PositionEffect => $trade->effect, $extraction->trades),
        );
        self::assertSame(self::PUT.'@USD', $extraction->trades[0]->fifoPool);
        self::assertSame('XCBO', $extraction->trades[0]->instrument?->exchangeCode);
    }

    public function testAnOptionRowWithoutAnOpenOrCloseCodeIsFatal(): void
    {
        $result = $this->import(self::withOptions([
            self::option('2026-03-02, 10:00:00', '1', '1.2', '-120', '-0.65', 'P'),
        ]));

        self::assertNotSame([], $result->errors());
    }

    public function testAnExpiryThatMovedCashIsFatal(): void
    {
        $result = $this->import(self::withOptions([
            self::option('2025-12-15, 15:00:00', '-1', '0.99', '99', '-1', 'O'),
            self::option('2026-01-16, 16:20:00', '1', '0.1', '-10', '0', 'C;Ep'),
        ]));

        self::assertNotSame([], $result->errors());
    }

    public function testAClosingSaleAtZeroThatCostAFeeIsFatal(): void
    {
        $result = $this->import(self::withOptions([
            self::option('2025-12-15, 15:00:00', '1', '1.2', '-120', '-0.65', 'O'),
            self::option('2026-01-16, 16:20:00', '-1', '0', '0', '-0.65', 'C;Ep'),
        ]));

        self::assertNotSame([], $result->errors());
    }

    public function testAnAssignedPutDeliversTheSharesAtTheStrikeAndSaysSo(): void
    {
        $result = $this->import(self::withOptions([
            self::option('2026-03-02, 10:00:00', '-1', '2', '200', '-1.05', 'O'),
            self::option('2026-04-17, 16:20:00', '1', '0', '0', '0', 'A;C'),
            self::trade('AAA', '2026-04-17, 16:20:00', '100', '50', '-5000', '0', 'A;O'),
        ]));

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->positions, 'The option closes; the shares stay open.');
        $review = self::joined(self::ofLevel($result->messages, MessageLevel::Review));
        self::assertStringContainsString(self::PUT, $review);
        self::assertStringContainsString('cenie wykonania', $review);
    }

    public function testACallSpreadExercisedAndAssignedAtOneInstantIsSettledNotRefused(): void
    {
        $long = 'AAA 17APR26 100 C';
        $short = 'AAA 17APR26 105 C';
        $content = self::statement([
            self::trade($long, '2026-03-02, 10:00:00', '1', '3', '-300', '-0.65', 'O', category: self::OPTIONS),
            self::trade($short, '2026-03-02, 10:00:01', '-1', '1.5', '150', '-0.65', 'O', category: self::OPTIONS),
            self::trade($long, '2026-04-17, 16:20:00', '-1', '0', '0', '0', 'C;Ex', category: self::OPTIONS),
            self::trade($short, '2026-04-17, 16:20:00', '1', '0', '0', '0', 'A;C', category: self::OPTIONS),
            // IBKR prints the delivered shares with one timestamp; the sale
            // happens to come first in the file.
            self::trade('AAA', '2026-04-17, 16:20:00', '-100', '105', '10500', '0', 'A;C'),
            self::trade('AAA', '2026-04-17, 16:20:00', '100', '100', '-10000', '0', 'Ex;O'),
        ], [
            self::FII_AAA,
            self::FII_OPTIONS_HEADER,
            'Financial Instrument Information,Data,Equity and Index Options,AAA   260417C00100000,'.$long.',2002,AAA,CBOE,100,2026-04-17,2026-04,C,100,',
            'Financial Instrument Information,Data,Equity and Index Options,AAA   260417C00105000,'.$short.',2003,AAA,CBOE,100,2026-04-17,2026-04,C,105,',
        ]);

        $result = $this->import($content);

        self::assertSame([], $result->errors());
        $shares = array_values(array_filter($result->positions, static fn ($p): bool => 'AAA' === $p->symbol));
        self::assertCount(1, $shares);
        self::assertSame('10000.00', (string) $shares[0]->buyAmount->value());
        self::assertSame('10500.00', (string) $shares[0]->sellAmount->value());
        self::assertStringContainsString('ten sam czas', self::joined(self::ofLevel($result->messages, MessageLevel::Review)));
    }

    public function testTwoPutsAssignedAtOneInstantAreSettledInFileOrder(): void
    {
        $high = 'AAA 17APR26 50 P';
        $low = 'AAA 17APR26 45 P';
        $content = self::statement([
            self::trade($high, '2026-03-02, 10:00:00', '-1', '2', '200', '-1.05', 'O', category: self::OPTIONS),
            self::trade($low, '2026-03-02, 10:00:01', '-1', '1', '100', '-1.05', 'O', category: self::OPTIONS),
            self::trade($high, '2026-04-17, 16:20:00', '1', '0', '0', '0', 'A;C', category: self::OPTIONS),
            self::trade($low, '2026-04-17, 16:20:00', '1', '0', '0', '0', 'A;C', category: self::OPTIONS),
            self::trade('AAA', '2026-04-17, 16:20:00', '100', '50', '-5000', '0', 'A;O'),
            self::trade('AAA', '2026-04-17, 16:20:00', '100', '45', '-4500', '0', 'A;O'),
        ], [
            self::FII_AAA,
            self::FII_OPTIONS_HEADER,
            'Financial Instrument Information,Data,Equity and Index Options,AAA   260417P00050000,'.$high.',2004,AAA,CBOE,100,2026-04-17,2026-04,P,50,',
            'Financial Instrument Information,Data,Equity and Index Options,AAA   260417P00045000,'.$low.',2005,AAA,CBOE,100,2026-04-17,2026-04,P,45,',
        ]);

        $result = $this->import($content);

        self::assertSame([], $result->errors());
        self::assertCount(2, $result->positions, 'Both written puts close; the shares stay open.');
    }

    public function testOrdinaryExecutionsAtOneInstantAreStillRefused(): void
    {
        $result = $this->import(self::statement([
            self::trade('AAA', '2025-03-03, 10:00:00', '1', '100', '-100', '-1'),
            self::trade('AAA', '2025-03-03, 10:00:00', '1', '101', '-101', '-1'),
        ]));

        self::assertNotSame([], $result->errors());
    }

    public function testABlankUnderlyingFallsBackToTheSeriesSymbol(): void
    {
        $result = $this->import(self::statement([
            self::option('2026-03-02, 10:00:00', '-1', '2', '200', '-1.05', 'O'),
            self::option('2026-04-17, 16:20:00', '1', '0', '0', '0', 'A;C'),
            self::trade('AAA', '2026-04-17, 16:20:00', '100', '50', '-5000', '0', 'A;O'),
        ], [self::FII_AAA, self::FII_OPTIONS_HEADER, str_replace(',2001,AAA,CBOE,', ',2001,,CBOE,', self::FII_PUT)]));

        self::assertSame([], $result->errors());
    }

    public function testAnAssignmentWithoutDeliveredSharesIsFatal(): void
    {
        $result = $this->import(self::withOptions([
            self::option('2026-03-02, 10:00:00', '-1', '2', '200', '-1.05', 'O'),
            self::option('2026-04-17, 16:20:00', '1', '0', '0', '0', 'A;C'),
        ]));

        self::assertStringContainsString('rozliczona pieniężnie', self::joined($result->errors()));
    }

    public function testACashSettledExerciseClosesAtItsAmount(): void
    {
        $result = $this->import(self::withOptions([
            self::option('2026-03-02, 10:00:00', '1', '2', '-200', '-1.05', 'O'),
            self::option('2026-04-17, 16:20:00', '-1', '0', '350', '0', 'C;Ex'),
        ]));

        self::assertSame([], $result->errors());
        self::assertSame('350.00', (string) $result->positions[0]->sellAmount->value());
        self::assertStringContainsString('pieniężne', self::joined(self::ofLevel($result->messages, MessageLevel::Review)));
    }

    public function testAnOptionClosedInALaterStatementNeedsTheEarlierOne(): void
    {
        $importer = new IbkrActivityStatementImporter(new FifoMatcher());
        $older = $importer->extractTrades(new CsvSource('2025.csv', self::withOptions([
            self::option('2025-12-15, 15:00:00', '-1', '0.99', '99', '-1', 'O'),
        ])));
        $newer = $importer->extractTrades(new CsvSource('2026.csv', self::withOptions([
            self::option('2026-01-16, 16:20:00', '1', '0', '0', '0', 'C;Ep'),
        ])));

        self::assertCount(1, $importer->matchTrades([...$older->trades, ...$newer->trades])->positions);

        $alone = $importer->matchTrades($newer->trades);
        self::assertSame([], $alone->positions);
        self::assertSame([], $alone->errors());
        self::assertStringContainsString('wcześniejszy rok', self::joined(self::ofLevel($alone->messages, MessageLevel::Review)));
    }

    /**
     * @param list<string> $trades
     * @param list<string> $instruments
     * @param list<string> $more        whole lines placed between Trades and the instrument section
     */
    private static function statement(array $trades, array $instruments = [self::FII_AAA, self::FII_BBB], array $more = []): string
    {
        $lines = [self::PREAMBLE.self::TRADES_HEADER, ...$trades, ...$more, self::FII_HEADER, ...$instruments];

        return implode("\n", $lines)."\n";
    }

    private static function trade(
        string $symbol,
        string $when,
        string $quantity,
        string $price,
        string $proceeds,
        string $commission,
        string $code = 'O',
        string $currency = 'USD',
        string $category = 'Stocks',
    ): string {
        return sprintf(
            'Trades,Data,Order,%s,%s,%s,"%s",%s,%s,0,%s,%s,0,0,0,%s',
            $category,
            $currency,
            $symbol,
            $when,
            $quantity,
            $price,
            $proceeds,
            $commission,
            $code,
        );
    }

    /**
     * @param list<string> $trades
     */
    private static function withOptions(array $trades): string
    {
        return self::statement($trades, [self::FII_AAA, self::FII_OPTIONS_HEADER, self::FII_PUT]);
    }

    private static function option(string $when, string $quantity, string $price, string $proceeds, string $commission, string $code): string
    {
        return self::trade(self::PUT, $when, $quantity, $price, $proceeds, $commission, $code, category: self::OPTIONS);
    }

    private function import(string $content): ImportResult
    {
        return (new IbkrActivityStatementImporter(new FifoMatcher()))->import(new CsvSource('as.csv', $content));
    }

    /**
     * @param list<ImportMessage> $messages
     *
     * @return list<ImportMessage>
     */
    private static function ofLevel(array $messages, MessageLevel $level): array
    {
        return array_values(array_filter($messages, static fn (ImportMessage $m): bool => $m->level === $level));
    }

    /**
     * @param list<ImportMessage|string> $messages
     */
    private static function joined(array $messages): string
    {
        return implode("\n", array_map(
            static fn (ImportMessage|string $m): string => $m instanceof ImportMessage ? $m->message : $m,
            $messages,
        ));
    }
}
