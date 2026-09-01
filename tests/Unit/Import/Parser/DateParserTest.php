<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import\Parser;

use App\Exception\InvalidDateException;
use App\Import\Parser\DateParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DateParser::class)]
final class DateParserTest extends TestCase
{
    #[DataProvider('supportedFormats')]
    public function testParsesEveryFormatTheImportersEncounter(string $input, string $expected): void
    {
        self::assertSame($expected, DateParser::parse($input)->format('Y-m-d'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function supportedFormats(): iterable
    {
        yield 'iso' => ['2024-03-22', '2024-03-22'];
        yield 'ibkr compact' => ['20240322', '2024-03-22'];
        yield 'ibkr compact with time' => ['20250402;202000', '2025-04-02'];
        yield 'iso with time' => ['2024-03-22 14:05:00', '2024-03-22'];
        yield 'iso with semicolon time' => ['2024-03-22;140500', '2024-03-22'];
        yield 'iso with T' => ['2024-03-22T14:05:00', '2024-03-22'];
        yield 'polish dotted' => ['22.03.2024', '2024-03-22'];
        yield 'padded' => ['  2024-03-22  ', '2024-03-22'];
    }

    public function testTimeComponentIsDiscardedSoDatesCompareCleanly(): void
    {
        self::assertSame('00:00:00', DateParser::parse('20250402;202000')->format('H:i:s'));
        self::assertSame('00:00:00', DateParser::parse('2024-03-22 14:05:00')->format('H:i:s'));
    }

    /**
     * DEGIRO writes every date as DD-MM-YYYY regardless of the export language.
     */
    public function testParsesDegiroDayFirstDashedDates(): void
    {
        self::assertSame('2025-03-15', DateParser::parse('15-03-2025')->format('Y-m-d'));
        self::assertSame('2024-12-29', DateParser::parse('29-12-2024')->format('Y-m-d'));
        self::assertSame('2025-01-02', DateParser::parse('02-01-2025')->format('Y-m-d'));
    }

    #[DataProvider('invalidDates')]
    public function testRejectsInvalidDates(string $input): void
    {
        $this->expectException(InvalidDateException::class);

        DateParser::parse($input);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDates(): iterable
    {
        yield 'empty' => [''];
        yield 'text' => ['blah'];
        yield 'impossible month' => ['2024-13-01'];
        yield 'impossible day' => ['2024-02-31'];
        yield 'impossible compact' => ['20241301'];
        yield 'too short' => ['2024'];
        yield 'excel serial' => ['45000'];
        // Day-first notation must stay strict: rolling 31-02 over to 03-03
        // would silently move a sale into another month, and a two-digit year
        // is ambiguous.
        yield 'day first february 31st' => ['31-02-2025'];
        yield 'day first month 13' => ['01-13-2025'];
        yield 'day first day zero' => ['00-01-2025'];
        yield 'day first two digit year' => ['15-03-25'];
    }
}
