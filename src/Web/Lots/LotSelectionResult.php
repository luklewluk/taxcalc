<?php

declare(strict_types=1);

namespace App\Web\Lots;

use App\Fifo\LotAssignments;
use App\Web\Diagnostic;

final readonly class LotSelectionResult
{
    /**
     * @param list<string>     $errors      blocking messages - only for a field that cannot be read
     * @param list<Diagnostic> $diagnostics
     */
    public function __construct(
        public LotAssignments $assignments,
        /** What the hidden field carries on the next render. */
        public string $field,
        public bool $fieldValid,
        public ?LotEditorRequest $editor = null,
        public array $errors = [],
        public array $diagnostics = [],
    ) {
    }
}
