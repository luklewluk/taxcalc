<?php

declare(strict_types=1);

namespace App\Web;

use App\Model\ClosedPosition;
use App\Model\Dividend;
use App\Fifo\Trade;
use App\Model\AccountFee;

final readonly class MappedRows
{
    /**
     * @param list<ClosedPosition>              $positions
     * @param list<Dividend>                    $dividends
     * @param list<string>                      $errors
     * @param list<array<string, string>>       $rows      every submitted row that was
     *                                                     not deleted, valid or not, so the
     *                                                     review screen can re-render the
     *                                                     user's own input for correction
     */
    public function __construct(
        public array $positions,
        public array $dividends,
        public array $errors,
        public array $rows = [],
        /** @var list<Trade> */
        public array $trades = [],
        /** @var list<AccountFee> */
        public array $fees = [],
        /** @var list<Diagnostic> */
        public array $diagnostics = [],
    ) {
    }
}
