<?php

declare(strict_types=1);

namespace App\Fifo;

/**
 * Why a queue could not be matched. Each is as fatal as a sale without a
 * purchase: settling around it would drop a premium or a cost silently.
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

    // A sale tied to named lots (specific identification) that cannot be
    // honoured. Blocking, and never replaced by FIFO in silence.
    /** A named lot is not among the trades at all. */
    case LotMissing = 'lot_missing';
    /** A named lot is not a buy of this queue (another instrument, broker or currency). */
    case LotNotEligible = 'lot_not_eligible';
    /** A named lot was bought after the sale. */
    case LotNotYetOpen = 'lot_not_open';
    /** A named lot holds fewer shares at the sale than named - earlier sales used them. */
    case LotInsufficient = 'lot_insufficient';
    /** The named quantities do not add up to the quantity sold. */
    case LotQuantityMismatch = 'lot_quantity_mismatch';
    /** Lots were named for a trade that is not a stock sale. */
    case AssignmentNotASale = 'lot_not_a_sale';

    public function isLotAssignment(): bool
    {
        return match ($this) {
            self::LotMissing, self::LotNotEligible, self::LotNotYetOpen, self::LotInsufficient,
            self::LotQuantityMismatch, self::AssignmentNotASale => true,
            default => false,
        };
    }
}
