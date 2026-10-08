<?php

declare(strict_types=1);

namespace App\Web;

use App\Settlement\SettlementCycle;
use App\Tax\AccountFeeTreatment;
use App\Tax\CreditMethod;

/**
 * Reads the settings back off the form, and offers their vocabularies to the
 * template.
 *
 * Modelled on {@see TaxYearProvider}: `normalize()` is **total**. An unknown,
 * stale or tampered value falls back to the default rather than failing the
 * submission - a settings field is not worth blocking a tax calculation over,
 * and the default is always a defensible reading.
 */
final readonly class SettingsProvider
{
    public function normalize(
        mixed $countrySource,
        mixed $creditMethod,
        mixed $settlementCycle = null,
        mixed $accountFees = null,
    ): WorkbenchSettings {
        return new WorkbenchSettings(
            self::source($countrySource) ?? CountrySource::Exchange,
            self::method($creditMethod) ?? CreditMethod::Conservative,
            self::cycle($settlementCycle) ?? SettlementCycle::TradeDate,
            is_scalar($accountFees) ? AccountFeeTreatment::tryFrom((string) $accountFees) ?? AccountFeeTreatment::Excluded : AccountFeeTreatment::Excluded,
        );
    }

    /**
     * @return array<string, string> value => label, for the settings select
     */
    public function accountFeeTreatments(): array
    {
        $options = [];
        foreach (AccountFeeTreatment::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public function accountFeeTreatmentHelp(): array
    {
        $help = [];
        foreach (AccountFeeTreatment::cases() as $case) {
            $help[$case->value] = $case->description();
        }

        return $help;
    }

    /**
     * @return array<string, string> value => label, for the settings select
     */
    public function settlementCycles(): array
    {
        $options = [];
        foreach (SettlementCycle::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public function settlementCycleHelp(): array
    {
        $help = [];
        foreach (SettlementCycle::cases() as $case) {
            $help[$case->value] = $case->description();
        }

        return $help;
    }

    /**
     * @return array<string, string> value => label, for the settings select
     */
    public function countrySources(): array
    {
        $options = [];
        foreach (CountrySource::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public function creditMethods(): array
    {
        $options = [];
        foreach (CreditMethod::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * Value => explanation, so the settings panel can say what each choice does
     * to the tax figure without the template reaching for a static `cases()`.
     *
     * @return array<string, string>
     */
    public function countrySourceHelp(): array
    {
        $help = [];
        foreach (CountrySource::cases() as $case) {
            $help[$case->value] = $case->description();
        }

        return $help;
    }

    /**
     * @return array<string, string>
     */
    public function creditMethodHelp(): array
    {
        $help = [];
        foreach (CreditMethod::cases() as $case) {
            $help[$case->value] = $case->description();
        }

        return $help;
    }

    private static function source(mixed $value): ?CountrySource
    {
        return is_scalar($value) ? CountrySource::tryFrom((string) $value) : null;
    }

    private static function method(mixed $value): ?CreditMethod
    {
        return is_scalar($value) ? CreditMethod::tryFrom((string) $value) : null;
    }

    private static function cycle(mixed $value): ?SettlementCycle
    {
        return is_scalar($value) ? SettlementCycle::tryFrom((string) $value) : null;
    }
}
