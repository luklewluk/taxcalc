<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import\Parser;

use App\Exception\InvalidNumberException;
use App\Import\Parser\NumberParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(NumberParser::class)]
final class NumberParserTest extends TestCase
{
    #[DataProvider('brokerNumbers')]
    public function testParsesBrokerStyleNumbersWhereCommaGroupsThousands(string $input, string $expected): void
    {
        self::assertSame($expected, (string) NumberParser::parse($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function brokerNumbers(): iterable
    {
        yield 'plain' => ['1523.98', '1523.98'];
        yield 'negative' => ['-1523.98', '-1523.98'];
        yield 'thousands separator' => ['1,523.98', '1523.98'];
        yield 'multiple groups' => ['1,234,567.89', '1234567.89'];
        yield 'four decimals' => ['507.5782', '507.5782'];
        yield 'leading plus' => ['+12.50', '12.50'];
        yield 'surrounding spaces' => ['  12.50  ', '12.50'];
        yield 'non breaking space' => ["12\u{00A0}500.10", '12500.10'];
        yield 'accounting negative' => ['(0.20)', '-0.20'];
        yield 'integer' => ['3', '3'];
        yield 'zero' => ['0', '0'];
    }

    public function testCommaIsThousandsSeparatorByDefault(): void
    {
        self::assertSame('1234', (string) NumberParser::parse('1,234'));
    }

    public function testPolishDecimalCommaIsAcceptedWhenExplicitlyEnabled(): void
    {
        self::assertSame('1234.56', (string) NumberParser::parse('1234,56', decimalComma: true));
        self::assertSame('0.2025', (string) NumberParser::parse('0,2025', decimalComma: true));
        // A dot still wins when both separators are present.
        self::assertSame('1234.56', (string) NumberParser::parse('1,234.56', decimalComma: true));
    }

    /**
     * DEGIRO exports the same file in either notation depending on the account
     * language, and a single upload may mix them, so the separator has to be
     * decided per value: whichever of the two appears last is the decimal point.
     */
    #[DataProvider('mixedSeparatorNumbers')]
    public function testTheLastSeparatorDecidesWhichOneIsTheDecimalPoint(string $input, string $expected): void
    {
        self::assertSame($expected, (string) NumberParser::parse($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function mixedSeparatorNumbers(): iterable
    {
        yield 'european thousands dot' => ['1.431,00', '1431.00'];
        yield 'english thousands comma' => ['1,234.56', '1234.56'];
        yield 'european multiple groups' => ['1.234.567,89', '1234567.89'];
        yield 'european negative' => ['-2.645,00', '-2645.00'];
        yield 'european four decimals' => ['1.523,9812', '1523.9812'];
        yield 'european accounting negative' => ['(1.755,00)', '-1755.00'];
        yield 'english multiple groups' => ['1,234,567.89', '1234567.89'];
        yield 'single leading group digit' => ['1.234,56', '1234.56'];
        yield 'three leading group digits' => ['123.456,78', '123456.78'];
    }

    public function testAGroupSeparatorMustGroupExactlyThreeDigits(): void
    {
        self::assertSame('1234', (string) NumberParser::parse('1,234'));
        self::assertSame('1234567', (string) NumberParser::parse('1,234,567'));

        // A decimal comma is a caller decision and is not subject to the
        // grouping rule - it just needs digits on both sides.
        self::assertSame('1234.56', (string) NumberParser::parse('1234,56', decimalComma: true));
        self::assertSame('0.5', (string) NumberParser::parse('0,5', decimalComma: true));
    }

    #[DataProvider('malformedGroupingInDecimalCommaMode')]
    public function testDecimalCommaModeStillRejectsMalformedGrouping(string $input): void
    {
        $this->expectException(InvalidNumberException::class);

        NumberParser::parse($input, decimalComma: true);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedGroupingInDecimalCommaMode(): iterable
    {
        yield 'two commas' => ['1,2,3'];
        yield 'group of two' => ['12.34,56'];
        yield 'leading comma' => [',123'];
    }

    #[DataProvider('garbage')]
    public function testRejectsNonNumericInput(string $input): void
    {
        $this->expectException(InvalidNumberException::class);

        NumberParser::parse($input);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function garbage(): iterable
    {
        yield 'empty' => [''];
        yield 'only spaces' => ['   '];
        yield 'text' => ['n/a'];
        yield 'currency suffix' => ['12.50 USD'];
        yield 'formula' => ['=1+1'];
        yield 'two dots' => ['1.2.3'];
        yield 'dash only' => ['-'];
        // Grouping that fits neither notation. A guess here moves a figure by a
        // factor of ten or a thousand, so the value is refused instead.
        yield 'group of two before the decimal comma' => ['12.34,56'];
        yield 'group of two before the decimal dot' => ['1,23.45'];
        yield 'single digit groups' => ['1,2,3'];
        yield 'leading separator' => [',123'];
        yield 'leading dot separator' => ['1,234.'];
        yield 'four digit group' => ['1,2345.67'];
        yield 'trailing group separator' => ['1,234,'];
        // Both separators present but the grouping is not consistent with
        // either notation - a guess here could shift a figure by 1000x.
        yield 'two commas after the dot' => ['1.2,3,4'];
        yield 'two dots after the comma' => ['1,2.3.4'];
    }

    public function testParseOrZeroTreatsBlanksAsZeroButStillRejectsGarbage(): void
    {
        self::assertSame('0', (string) NumberParser::parseOrZero(''));
        self::assertSame('0', (string) NumberParser::parseOrZero('   '));
        self::assertSame('1.50', (string) NumberParser::parseOrZero('1.50'));

        $this->expectException(InvalidNumberException::class);
        NumberParser::parseOrZero('n/a');
    }
}
