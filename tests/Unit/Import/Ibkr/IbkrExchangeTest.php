<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import\Ibkr;

use App\Import\Degiro\ExchangeCountry;
use App\Import\Ibkr\IbkrExchange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(IbkrExchange::class)]
final class IbkrExchangeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function listings(): iterable
    {
        yield 'Nasdaq' => ['NASDAQ', 'XNAS', 'US'];
        yield 'NYSE' => ['NYSE', 'XNYS', 'US'];
        yield 'London ETF segment' => ['LSEETF', 'XLON', 'GB'];
        yield 'Xetra' => ['IBIS2', 'XETR', 'DE'];
        yield 'Amsterdam' => ['AEB', 'XAMS', 'NL'];
        yield 'IBKR TSE is Toronto' => ['TSE', 'XTSE', 'CA'];
        yield 'IBKR TSEJ is Tokyo' => ['TSEJ', 'XTKS', 'JP'];
        yield 'Warsaw' => ['WSE', 'XWAR', 'PL'];
        yield 'case and spaces' => [' nasdaq ', 'XNAS', 'US'];
    }

    #[DataProvider('listings')]
    public function testAListingExchangeBecomesAMicWithACountry(string $code, string $mic, string $country): void
    {
        self::assertSame($mic, IbkrExchange::mic($code));
        self::assertSame($country, ExchangeCountry::country($mic));
    }

    public function testAnUnknownCodeMapsToNothing(): void
    {
        self::assertNull(IbkrExchange::mic('MOONEX'));
        self::assertNull(IbkrExchange::mic(''));
    }

    public function testEveryMappedMicNamesACountry(): void
    {
        foreach (IbkrExchange::all() as $code => $mic) {
            self::assertNotSame('', ExchangeCountry::country($mic), sprintf('%s -> %s', $code, $mic));
        }
    }
}
