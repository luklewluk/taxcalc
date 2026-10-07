<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\Format;

use App\Money\Amount;
use App\Money\Decimal;
use App\Web\Format\MoneyFormatter;
use PHPUnit\Framework\TestCase;

/**
 * The formatter is presentation only, and it must be provably textual: a money
 * figure that went through a float would be a bug that no amount of UI polish
 * makes acceptable.
 */
final class MoneyFormatterTest extends TestCase
{
    private const string NBSP = "\u{00a0}";

    private MoneyFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new MoneyFormatter();
    }

    public function testGroziesUseACommaAndThousandsANonBreakingSpace(): void
    {
        self::assertSame('1'.self::NBSP.'523,98', $this->formatter->format(Decimal::of('1523.98')));
        self::assertSame('16,00', $this->formatter->format(Decimal::of('16.00')));
    }

    public function testAPostedAmountIsFormattedOnlyWhenItIsADecimal(): void
    {
        self::assertSame('1'.self::NBSP.'001,00', $this->formatter->formatText('1001.00'));
        self::assertSame('507,5782', $this->formatter->formatText(' 507.5782 '));
        self::assertSame('1,5e3', $this->formatter->formatText('1,5e3'), 'What the user typed is shown as typed.');
        self::assertSame('', $this->formatter->formatText(''));
    }

    public function testEveryGroupOfThreeDigitsIsSeparated(): void
    {
        self::assertSame(
            '1'.self::NBSP.'234'.self::NBSP.'567,89',
            $this->formatter->format(Decimal::of('1234567.89')),
        );
        self::assertSame(
            '12'.self::NBSP.'345'.self::NBSP.'678',
            $this->formatter->format(Decimal::of('12345678')),
        );
    }

    public function testShortValuesAreNotGrouped(): void
    {
        self::assertSame('0', $this->formatter->format(Decimal::zero()));
        self::assertSame('0,00', $this->formatter->format(Decimal::of('0.00')));
        self::assertSame('999,99', $this->formatter->format(Decimal::of('999.99')));
    }

    public function testTheSignStaysInFrontOfTheGroupedDigits(): void
    {
        self::assertSame(
            '-1'.self::NBSP.'523,98',
            $this->formatter->format(Decimal::of('-1523.98')),
        );
        self::assertSame('1'.self::NBSP.'000', $this->formatter->format(Decimal::of('+1000')));
    }

    /**
     * The scale the domain carried is the scale that gets printed. Silently
     * rounding a four-decimal broker figure to grosze in an audit table would
     * make the table disagree with the source document.
     */
    public function testTheValuesOwnPrecisionIsPreserved(): void
    {
        self::assertSame('507,5782', $this->formatter->format(Decimal::of('507.5782')));
        self::assertSame('0,2025', $this->formatter->format(Decimal::of('0.2025')));
        self::assertSame('1,5', $this->formatter->format(Decimal::of('1.5')));
    }

    public function testRoundingHappensOnlyWhenAScaleIsAskedFor(): void
    {
        self::assertSame('0,20', $this->formatter->format(Decimal::of('0.2025'), 2));
        self::assertSame('0,21', $this->formatter->format(Decimal::of('0.2050'), 2));
        self::assertSame('1', $this->formatter->format(Decimal::of('0.5'), 0));
    }

    public function testAnExactValueTooLargeForAFloatSurvivesUnchanged(): void
    {
        $huge = '123456789012345678901234567890.12';

        self::assertSame(
            '123'.self::NBSP.'456'.self::NBSP.'789'.self::NBSP.'012'.self::NBSP.'345'
            .self::NBSP.'678'.self::NBSP.'901'.self::NBSP.'234'.self::NBSP.'567'.self::NBSP.'890,12',
            $this->formatter->format(Decimal::of($huge)),
        );
    }

    public function testAnAmountIsFormattedFromItsDecimal(): void
    {
        self::assertSame('1'.self::NBSP.'523,98', $this->formatter->format(Amount::of('1523.98', 'USD')));
    }

    public function testTheLocalCurrencyIsShownAsZlotyAndForeignOnesByCode(): void
    {
        self::assertSame(
            '1'.self::NBSP.'523,98 zł',
            $this->formatter->formatWithCurrency(Amount::of('1523.98', 'PLN')),
        );
        self::assertSame('56,75 USD', $this->formatter->formatWithCurrency(Amount::of('56.75', 'USD')));
        self::assertSame('zł', $this->formatter->currencySymbol('PLN'));
        self::assertSame('CHF', $this->formatter->currencySymbol('CHF'));
    }

    public function testTheSeparatorIsANonBreakingSpaceSoAFigureNeverWraps(): void
    {
        self::assertSame(self::NBSP, MoneyFormatter::GROUP_SEPARATOR);
        self::assertStringNotContainsString(' ', $this->formatter->format(Decimal::of('1000.00')));
    }
}
