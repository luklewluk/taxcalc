<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web;

use App\Settlement\SettlementCycle;
use App\Tax\AccountFeeTreatment;
use App\Tax\CreditMethod;
use App\Web\CountrySource;
use App\Web\SettingsProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SettingsProvider::class)]
final class SettingsProviderTest extends TestCase
{
    private SettingsProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new SettingsProvider();
    }

    public function testDefaultsAreTheListingExchangeAndTheConservativeVariant(): void
    {
        $settings = $this->provider->normalize(null, null);

        self::assertSame(CountrySource::Exchange, $settings->countrySource);
        self::assertSame(CreditMethod::Conservative, $settings->creditMethod);
        self::assertSame(SettlementCycle::TradeDate, $settings->settlementCycle);
        self::assertSame(AccountFeeTreatment::Excluded, $settings->accountFees);
    }

    /**
     * Total on purpose, exactly like TaxYearProvider::normalize(): a tampered
     * or stale value silently falls back rather than blocking a settlement.
     */
    public function testAnUnknownValueFallsBackToTheDefault(): void
    {
        $settings = $this->provider->normalize('nonsense', ['array'], 'T+5', 'sometimes');

        self::assertSame(CountrySource::Exchange, $settings->countrySource);
        self::assertSame(CreditMethod::Conservative, $settings->creditMethod);
        self::assertSame(SettlementCycle::TradeDate, $settings->settlementCycle);
        self::assertSame(AccountFeeTreatment::Excluded, $settings->accountFees);
    }

    public function testKnownValuesAreAccepted(): void
    {
        $settings = $this->provider->normalize('isin', 'nsa', 'market', 'included');

        self::assertSame(CountrySource::Isin, $settings->countrySource);
        self::assertSame(CreditMethod::Nsa, $settings->creditMethod);
        self::assertSame(SettlementCycle::Market, $settings->settlementCycle);
        self::assertSame(AccountFeeTreatment::Included, $settings->accountFees);
        self::assertTrue($settings->accountFees->isCost());
    }

    public function testBothVocabulariesAreOfferedWithPolishLabels(): void
    {
        $sources = $this->provider->countrySources();
        $methods = $this->provider->creditMethods();

        self::assertSame(['exchange', 'isin'], array_keys($sources));
        self::assertSame(['conservative', 'nsa'], array_keys($methods));

        foreach ([...array_values($sources), ...array_values($methods)] as $label) {
            self::assertNotSame('', trim($label));
            self::assertMatchesRegularExpression('/[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]|giełd|ISIN|KIS|NSA/u', $label);
        }
    }

    public function testEverySettlementCycleIsOfferedAndExplained(): void
    {
        self::assertSame(['trade_date', 'market', 'pl_d2'], array_keys($this->provider->settlementCycles()));
        foreach ($this->provider->settlementCycleHelp() as $help) {
            self::assertStringContainsString('kurs nbp', mb_strtolower($help));
        }
    }

    public function testBothAccountFeeTreatmentsAreOfferedAndExplained(): void
    {
        self::assertSame(['excluded', 'included'], array_keys($this->provider->accountFeeTreatments()));
        foreach ($this->provider->accountFeeTreatmentHelp() as $help) {
            self::assertStringContainsString('koszt', mb_strtolower($help));
        }
    }

    public function testEveryCountrySourceExplainsItsTaxConsequence(): void
    {
        foreach (CountrySource::cases() as $source) {
            self::assertNotSame('', trim($source->label()));
            self::assertStringContainsString('dochod', mb_strtolower($source->description()));
        }
    }
}
