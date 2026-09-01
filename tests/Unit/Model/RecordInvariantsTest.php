<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Exception\InvalidRecordException;
use App\Model\ClosedPosition;
use App\Model\Dividend;
use App\Money\Amount;
use App\Money\Decimal;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The normalized records are the single choke point every importer and every
 * form submission passes through, so the sign and magnitude rules are enforced
 * here rather than repeated at each boundary.
 */
#[CoversClass(ClosedPosition::class)]
#[CoversClass(Dividend::class)]
final class RecordInvariantsTest extends TestCase
{
    public function testNegativeBuyAmountIsRejected(): void
    {
        $this->expectException(InvalidRecordException::class);

        self::position(buy: '-100.00');
    }

    public function testZeroBuyAmountIsRejected(): void
    {
        $this->expectException(InvalidRecordException::class);

        self::position(buy: '0');
    }

    public function testNegativeSellAmountIsRejected(): void
    {
        $this->expectException(InvalidRecordException::class);

        self::position(sell: '-1');
    }

    public function testZeroSellAmountIsRejected(): void
    {
        $this->expectException(InvalidRecordException::class);

        self::position(sell: '0.00');
    }

    public function testNonPositiveQuantityIsRejected(): void
    {
        $this->expectException(InvalidRecordException::class);

        self::position(quantity: '0');
    }

    public function testNegativeQuantityIsRejected(): void
    {
        $this->expectException(InvalidRecordException::class);

        self::position(quantity: '-3');
    }

    public function testAbsentQuantityIsAllowedBecauseTheNormalizedFormatOmitsIt(): void
    {
        $position = self::position(quantity: null);

        self::assertNull($position->quantity);
    }

    public function testAmountCurrencyMustMatchTheDeclaredCurrency(): void
    {
        $this->expectException(InvalidRecordException::class);

        new ClosedPosition(
            'AAA',
            'US',
            'USD',
            new DateTimeImmutable('2024-01-01'),
            Amount::of('10.00', 'EUR'),
            new DateTimeImmutable('2024-06-01'),
            Amount::of('15.00', 'USD'),
            null,
            'test',
        );
    }

    public function testValidPositionIsAccepted(): void
    {
        $position = self::position();

        self::assertSame('10.00', (string) $position->buyAmount->value());
        self::assertSame('15.00', (string) $position->sellAmount->value());
    }

    public function testNegativeDividendGrossIsRejectedRatherThanFlipped(): void
    {
        $this->expectException(InvalidRecordException::class);

        self::dividend(gross: '-100.00');
    }

    public function testZeroDividendGrossIsRejected(): void
    {
        $this->expectException(InvalidRecordException::class);

        self::dividend(gross: '0');
    }

    public function testNegativeWithheldTaxIsRejectedAtTheModelBoundary(): void
    {
        // Broker formats report withholding as negative; normalising the sign is
        // the importer's job, so by the time a record exists it must be >= 0.
        $this->expectException(InvalidRecordException::class);

        self::dividend(withheld: '-12.30');
    }

    public function testZeroWithheldTaxIsAllowed(): void
    {
        $dividend = self::dividend(withheld: '0');

        self::assertTrue($dividend->withheldTax->isZero());
    }

    public function testWithheldTaxCannotExceedGrossDividend(): void
    {
        $this->expectException(InvalidRecordException::class);

        self::dividend(gross: '10.00', withheld: '20.00');
    }

    public function testDividendCurrencyMustMatch(): void
    {
        $this->expectException(InvalidRecordException::class);

        new Dividend(
            'AAA',
            'US',
            'USD',
            new DateTimeImmutable('2025-04-02'),
            Amount::of('100.00', 'USD'),
            Amount::of('15.00', 'EUR'),
            'test',
        );
    }

    private static function position(
        string $buy = '10.00',
        string $sell = '15.00',
        ?string $quantity = '3',
    ): ClosedPosition {
        return new ClosedPosition(
            'AAA',
            'US',
            'USD',
            new DateTimeImmutable('2024-01-01'),
            Amount::of($buy, 'USD'),
            new DateTimeImmutable('2024-06-01'),
            Amount::of($sell, 'USD'),
            null === $quantity ? null : Decimal::of($quantity),
            'test',
        );
    }

    private static function dividend(string $gross = '100.00', string $withheld = '15.00'): Dividend
    {
        return new Dividend(
            'AAA',
            'US',
            'USD',
            new DateTimeImmutable('2025-04-02'),
            Amount::of($gross, 'USD'),
            Amount::of($withheld, 'USD'),
            'test',
        );
    }
}
