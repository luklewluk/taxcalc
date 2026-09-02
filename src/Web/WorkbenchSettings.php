<?php

declare(strict_types=1);

namespace App\Web;

use App\Tax\CreditMethod;

/**
 * The choices the user made about how to read ambiguous tax rules.
 *
 * Rides the workbench form like every other piece of state - nothing about a
 * settlement is ever persisted, so a setting is a posted field, never a session
 * value. {@see SettingsProvider} is what turns the raw post back into this.
 */
final readonly class WorkbenchSettings
{
    public function __construct(
        /** Which country a *trade* declares; a dividend always follows its ISIN. */
        public CountrySource $countrySource = CountrySource::Exchange,
        /**
         * Which reading of the foreign-tax credit fills the PIT fields. The
         * other one stays visible everywhere for comparison - the dispute is
         * live, so the calculator may headline a variant but never hide one.
         */
        public CreditMethod $creditMethod = CreditMethod::Conservative,
    ) {
    }

    public function alternativeCreditMethod(): CreditMethod
    {
        return CreditMethod::Conservative === $this->creditMethod
            ? CreditMethod::Nsa
            : CreditMethod::Conservative;
    }
}
