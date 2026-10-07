<?php

declare(strict_types=1);

namespace App\Fifo;

/**
 * Why an option queue could not be matched. Each is as fatal as a sale without
 * a purchase: settling around it would drop a premium or a cost silently.
 */
enum FifoViolationKind: string
{
    /**
     * A close found no open position on the opposite side to close - an earlier
     * statement is missing. Like an unmatched stock sale, a review item that
     * leaves the close out; every other kind contradicts the data and blocks.
     */
    case UnmatchedClose = 'option_unmatched_close';
    /** An open while the opposite side still had lots - a close declared as an open. */
    case OpenAgainstOpposite = 'option_open_against_position';
    /** One queue holds stocks and options; they never settle against each other. */
    case MixedInstrumentKinds = 'mixed_instrument_kinds';
    /** An option trade that does not say whether it opens or closes. */
    case MissingEffect = 'option_effect_missing';
}
