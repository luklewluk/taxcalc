<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import\Ibkr;

use App\Import\CsvSource;
use App\Import\Ibkr\ActivityStatementReader;
use App\Import\Ibkr\ActivityStatementReadException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActivityStatementReader::class)]
final class ActivityStatementReaderTest extends TestCase
{
    private const string STATEMENT = "\u{FEFF}Statement,Header,Field Name,Field Value\n"
        ."Statement,Data,Title,Activity Statement\n"
        ."Account Information,Header,Field Name,Field Value\n"
        ."Account Information,Data,Name,Jan Przykładowy\n"
        ."Account Information,Data,Account,UXXXXXXXX\n"
        ."Trades,Header,DataDiscriminator,Asset Category,Currency,Symbol,Date/Time,Quantity,T. Price,C. Price,Proceeds,Comm/Fee,Basis,Realized P/L,MTM P/L,Code\n"
        ."Trades,Data,Order,Stocks,USD,AAA,\"2026-06-22, 09:55:39\",3,238.77,232.79,-716.31,-0.35,716.66,0,-17.94,O\n"
        .'Trades,SubTotal,,Stocks,USD,AAA,,3,,,-716.31,-0.35,716.66,0,-17.94,'."\n"
        .'Trades,Total,,Stocks,USD,,,,,,-716.31,-0.35,716.66,0,-17.94,'."\n"
        ."Trades,Header,DataDiscriminator,Asset Category,Currency,Symbol,Date/Time,Quantity,T. Price,,Proceeds,Comm in USD,,,MTM in USD,Code\n"
        ."Trades,Data,Order,Forex,PLN,USD.PLN,\"2026-01-16, 06:19:31\",17,3.63542,,-61.80214,0,,,0.013064,AFx\n"
        ."Financial Instrument Information,Header,Asset Category,Symbol,Description,Conid,Security ID,Underlying,Listing Exch,Multiplier,Type,Code\n"
        ."Financial Instrument Information,Data,Stocks,AAA,ALFA CORP,1001,US000ALFA001,AAA,NASDAQ,1,COMMON,\n";

    private const array SECTIONS = ['Trades', 'Financial Instrument Information'];

    public function testDataRowsAreKeyedByTheHeaderInForceAtTheTime(): void
    {
        $statement = ActivityStatementReader::read(new CsvSource('as.csv', self::STATEMENT), self::SECTIONS, 100);
        $trades = $statement->rows('Trades');

        self::assertCount(2, $trades, 'SubTotal and Total rows are not data.');

        self::assertSame('Stocks', $trades[0]->get('asset category'));
        self::assertSame('2026-06-22, 09:55:39', $trades[0]->get('date/time'), 'A quoted comma stays inside its field.');
        self::assertSame('-0.35', $trades[0]->get('comm/fee'));
        self::assertSame(7, $trades[0]->line);

        // The Forex block re-declares the header with its own commission column.
        self::assertSame('Forex', $trades[1]->get('asset category'));
        self::assertSame('0', $trades[1]->get('comm in usd'));
        self::assertSame('', $trades[1]->get('comm/fee'));
    }

    public function testASectionAfterTheTradesIsAvailableToo(): void
    {
        $statement = ActivityStatementReader::read(new CsvSource('as.csv', self::STATEMENT), self::SECTIONS, 100);

        $instruments = $statement->rows('Financial Instrument Information');
        self::assertCount(1, $instruments);
        self::assertSame('US000ALFA001', $instruments[0]->get('security id'));
        self::assertSame('NASDAQ', $instruments[0]->get('listing exch'));
    }

    public function testSectionsThatWereNotAskedForAreNeverKept(): void
    {
        $statement = ActivityStatementReader::read(new CsvSource('as.csv', self::STATEMENT), self::SECTIONS, 100);

        self::assertSame([], $statement->rows('Account Information'));
        self::assertFalse($statement->has('Account Information'));
        self::assertStringNotContainsString('UXXXXXXXX', serialize($statement));
        self::assertStringNotContainsString('Przykładowy', serialize($statement));
    }

    public function testASectionWithoutRowsIsAbsent(): void
    {
        $statement = ActivityStatementReader::read(new CsvSource('as.csv', self::STATEMENT), ['Dividends'], 100);

        self::assertFalse($statement->has('Dividends'));
        self::assertSame([], $statement->rows('Dividends'));
    }

    public function testExceedingTheRowLimitRejectsTheWholeFile(): void
    {
        $this->expectException(ActivityStatementReadException::class);
        $this->expectExceptionMessage('więcej niż 2 wierszy');

        ActivityStatementReader::read(new CsvSource('as.csv', self::STATEMENT), self::SECTIONS, 2);
    }

    public function testAnEmptyFileIsRejected(): void
    {
        $this->expectException(ActivityStatementReadException::class);

        ActivityStatementReader::read(new CsvSource('as.csv', "\n"), self::SECTIONS, 100);
    }
}
