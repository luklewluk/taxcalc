<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Fifo\InstrumentDetails;
use App\Fifo\Trade;
use App\Import\Dto\TransactionTax;
use App\Import\MessageLevel;
use App\Import\TransactionTaxApplier;
use App\Money\Amount;
use App\Money\Decimal;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A transaction tax charged on a purchase (the French one above all) arrives
 * on the account statement, apart from the trade. It belongs to that
 * purchase's cost: it is added to the buy's settled cash and to its fee.
 */
#[CoversClass(TransactionTaxApplier::class)]
#[CoversClass(TransactionTax::class)]
final class TransactionTaxApplierTest extends TestCase
{
    private const string ISIN = 'FR000ALFA001';

    public function testTheTaxJoinsTheSameDaysPurchase(): void
    {
        [$trades, $messages] = TransactionTaxApplier::apply(
            [self::buy('2023-03-10', '1000.00', '2.00'), self::sell('2023-11-06', '1100.00')],
            [self::tax('2023-03-10', '3.00')],
        );

        self::assertSame([], $messages);
        self::assertSame('1003.00', (string) $trades[0]->grossAmount->value());
        self::assertSame('5.00', (string) $trades[0]->commission?->value());
        self::assertSame('1100.00', (string) $trades[1]->grossAmount->value(), 'a sale is left alone');
    }

    public function testTwoPurchasesThatDayShareItInProportionToTheirValue(): void
    {
        [$trades] = TransactionTaxApplier::apply(
            [self::buy('2023-03-10', '1000.00'), self::buy('2023-03-10', '2000.00', fill: 2)],
            [self::tax('2023-03-10', '1.00')],
        );

        self::assertSame('1000.33', (string) $trades[0]->grossAmount->value());
        self::assertSame('2000.67', (string) $trades[1]->grossAmount->value());
    }

    public function testATaxBookedLaterFindsThePurchaseOfTheWeekBefore(): void
    {
        [$trades, $messages] = TransactionTaxApplier::apply(
            [self::buy('2023-03-08', '1000.00')],
            [self::tax('2023-03-10', '3.00')],
        );

        self::assertSame([], $messages);
        self::assertSame('1003.00', (string) $trades[0]->grossAmount->value());
    }

    public function testWithoutThePurchaseTheTaxIsAReviewItemAndNothingMoves(): void
    {
        [$trades, $messages] = TransactionTaxApplier::apply(
            [self::buy('2023-01-02', '1000.00')],
            [self::tax('2023-03-10', '3.00')],
        );

        self::assertSame('1000.00', (string) $trades[0]->grossAmount->value());
        self::assertCount(1, $messages);
        self::assertSame(MessageLevel::Review, $messages[0]->level);
        self::assertStringContainsString(self::ISIN, $messages[0]->message);
        self::assertStringContainsString('3.00 EUR', $messages[0]->message);
    }

    public function testAPurchaseInAnotherCurrencyIsNotGuessedAt(): void
    {
        [$trades, $messages] = TransactionTaxApplier::apply(
            [self::buy('2023-03-10', '1000.00', currency: 'USD')],
            [self::tax('2023-03-10', '3.00')],
        );

        self::assertSame('1000.00', (string) $trades[0]->grossAmount->value());
        self::assertCount(1, $messages);
    }

    private static function buy(string $date, string $total, ?string $commission = null, int $fill = 1, string $currency = 'EUR'): Trade
    {
        return new Trade(
            self::ISIN,
            new DateTimeImmutable($date.' 10:00:00'),
            Decimal::of('10'),
            Amount::of($total, $currency),
            'order-'.$date.'-'.$fill,
            'transactions.csv',
            new InstrumentDetails('ALFA SA', 'FR', 'EPA'),
            $fill,
            true,
            broker: 'DEGIRO',
            commission: null === $commission ? null : Amount::of($commission, $currency),
        );
    }

    private static function sell(string $date, string $total): Trade
    {
        return new Trade(
            self::ISIN,
            new DateTimeImmutable($date.' 10:00:00'),
            Decimal::of('-10'),
            Amount::of($total, 'EUR'),
            'order-sell',
            'transactions.csv',
            new InstrumentDetails('ALFA SA', 'FR', 'EPA'),
            broker: 'DEGIRO',
        );
    }

    private static function tax(string $date, string $amount): TransactionTax
    {
        return new TransactionTax(
            'DEGIRO',
            self::ISIN,
            new DateTimeImmutable($date),
            Amount::of($amount, 'EUR'),
            'Francuski podatek od transakcji',
            'account.csv',
            12,
            'tax-'.$date,
        );
    }
}
