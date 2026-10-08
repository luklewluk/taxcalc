<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tax;

use App\Model\AccountFee;
use App\Money\Amount;
use App\Tax\StockTaxCalculator;
use App\Tests\Support\FixedExchange;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AccountFeeTaxTest extends TestCase
{
    public function testIncludedChargeAddsCostAndCorrectionReducesIt(): void
    {
        $fees = [
            new AccountFee('fee', 'account', new DateTimeImmutable('2025-02-02'), 'USD', Amount::of('10', 'USD'), false, 'x', '1'),
            new AccountFee('refund', 'account', new DateTimeImmutable('2025-03-02'), 'USD', Amount::of('2', 'USD'), true, 'x', '2'),
            new AccountFee('off', 'account', new DateTimeImmutable('2025-03-02'), 'USD', Amount::of('99', 'USD'), false, 'x', '3', false),
        ];
        $result = (new StockTaxCalculator(FixedExchange::create()))->calculate([], $fees);

        self::assertSame('32.00', (string) $result->accountingFeesCost->value());
        self::assertSame('32.00', (string) $result->totalCost->value());
        self::assertCount(2, $result->accountingFees);
    }

    /** Leaving fees out of the costs keeps every other field - and the identity - as it was. */
    public function testAFeeCanBeLeftOutWithoutChangingIt(): void
    {
        $fee = new AccountFee('fee', 'account', new DateTimeImmutable('2025-02-02'), 'USD', Amount::of('10', 'USD'), false, 'x', '1');
        $excluded = $fee->withIncluded(false);

        self::assertFalse($excluded->included);
        self::assertSame($fee->id(), $excluded->id());
        self::assertSame('fee', $excluded->description);
        self::assertSame('0.00', (string) (new StockTaxCalculator(FixedExchange::create()))->calculate([], [$excluded])->accountingFeesCost->value());
    }
}
