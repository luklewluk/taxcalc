<?php

declare(strict_types=1);

namespace App\Web\Lots;

/**
 * The lot editor of one sale, open for this render: what was posted into it
 * after a failed save, and why it failed.
 */
final readonly class LotEditorRequest
{
    /**
     * @param array<string, string> $values lot id => the quantity as typed
     * @param list<string>          $errors
     */
    public function __construct(
        public string $saleId,
        public array $values = [],
        public array $errors = [],
    ) {
    }
}
