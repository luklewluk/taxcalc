<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web;

use App\Web\CountrySource;
use App\Web\CountrySourceApplier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CountrySourceApplier::class)]
final class CountrySourceApplierTest extends TestCase
{
    private CountrySourceApplier $applier;

    protected function setUp(): void
    {
        $this->applier = new CountrySourceApplier();
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private static function row(array $overrides = []): array
    {
        // Registered in China, listed in Germany (Tradegate) - the shape that
        // makes the two readings disagree.
        return $overrides + [
            'id' => 't1', 'broker' => 'DEGIRO', 'pool' => 'CN000EPSIL01',
            'symbol' => 'CN000EPSIL01', 'name' => 'EPSILON MOTORS',
            'country' => 'DE', 'exchange' => 'TDG',
        ];
    }

    /**
     * @param list<array<string, string>> $rows
     *
     * @return list<array<string, string>>
     */
    private function apply(array $rows, CountrySource $source): array
    {
        $this->applier->apply($rows, $source);

        return $rows;
    }

    public function testSwitchingToIsinRederivesTheProposal(): void
    {
        $rows = $this->apply([self::row()], CountrySource::Isin);

        self::assertSame('CN', $rows[0]['country']);
    }

    public function testAnIbkrRowFindsItsIsinInThePool(): void
    {
        // The Activity Statement keys its queue on ISIN@CURRENCY and keeps the
        // ticker as the symbol: an Irish ETF listed in London.
        $row = ['id' => 't2', 'broker' => 'IBKR', 'pool' => 'IE000BETA002@USD', 'symbol' => 'BBB',
            'name' => 'BETA ETF', 'country' => 'GB', 'exchange' => 'XLON'];

        self::assertSame('IE', $this->apply([$row], CountrySource::Isin)[0]['country']);
        self::assertSame('GB', $this->apply([['country' => 'IE'] + $row], CountrySource::Exchange)[0]['country']);
    }

    public function testATickerPoolHasNoIsinToOffer(): void
    {
        $row = ['id' => 't3', 'broker' => 'IBKR', 'pool' => 'AAA@USD', 'symbol' => 'AAA',
            'name' => 'AAA', 'country' => 'US', 'exchange' => 'XNAS'];

        self::assertSame('US', $this->apply([$row], CountrySource::Isin)[0]['country']);
    }

    public function testSwitchingBackToTheExchangeRederivesTheProposal(): void
    {
        $rows = $this->apply([self::row(['country' => 'CN'])], CountrySource::Exchange);

        self::assertSame('DE', $rows[0]['country']);
    }

    public function testABlankCountryIsFilledFromTheChosenSource(): void
    {
        self::assertSame('CN', $this->apply([self::row(['country' => ''])], CountrySource::Isin)[0]['country']);
        self::assertSame('DE', $this->apply([self::row(['country' => ''])], CountrySource::Exchange)[0]['country']);
    }

    /**
     * A value matching neither proposal was typed by the user, and a setting
     * must not overwrite a deliberate answer.
     */
    public function testAManualCountryIsLeftAlone(): void
    {
        $rows = $this->apply([self::row(['country' => 'HK'])], CountrySource::Isin);

        self::assertSame('HK', $rows[0]['country']);
    }

    public function testARowWithoutAnExchangeOrAnIsinIsLeftAlone(): void
    {
        $flat = ['id' => 't9', 'broker' => 'IBKR', 'pool' => 'CSPX@USD', 'symbol' => 'CSPX',
            'name' => 'CSPX', 'country' => 'IE', 'exchange' => ''];

        self::assertSame('IE', $this->apply([$flat], CountrySource::Isin)[0]['country']);
        self::assertSame('IE', $this->apply([$flat], CountrySource::Exchange)[0]['country']);
    }

    /**
     * A multi-venue code names no country, so there is nothing to switch to and
     * the ISIN-derived value must not be wiped.
     */
    public function testAnUnresolvableExchangeLeavesTheIsinValueInPlace(): void
    {
        $rows = $this->apply(
            [self::row(['exchange' => 'CEUX', 'country' => 'CN'])],
            CountrySource::Exchange,
        );

        self::assertSame('CN', $rows[0]['country']);
    }

    public function testRemovedRowsAreNotTouched(): void
    {
        $rows = $this->apply([self::row(['remove' => '1'])], CountrySource::Isin);

        self::assertSame('DE', $rows[0]['country']);
    }
}
