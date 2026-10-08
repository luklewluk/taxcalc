<?php

declare(strict_types=1);

namespace App\Web;

use App\Settlement\SettlementCycle;
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
        /**
         * Which country a *trade* declares. A dividend's country is the payer's
         * residence; its proposal always comes from the ISIN, which a depositary
         * receipt gets wrong.
         */
        public CountrySource $countrySource = CountrySource::Exchange,
        /**
         * Which reading of the foreign-tax credit the return uses. The domain
         * computes both; every surface shows this one only, named.
         */
        public CreditMethod $creditMethod = CreditMethod::Conservative,
        /**
         * Which day of a trade leg sets its NBP rate and - for the closing
         * leg - its tax year: the trade itself, or the day it settles.
         */
        public SettlementCycle $settlementCycle = SettlementCycle::TradeDate,
    ) {
    }
}
