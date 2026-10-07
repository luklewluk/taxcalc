<?php

declare(strict_types=1);

namespace App\Web;

/**
 * A machine-addressable workbench problem. Navigation is explicit metadata;
 * the UI never guesses a destination by searching the Polish message text.
 */
final readonly class Diagnostic
{
    /**
     * Findings about what FIFO made of a row, not about what the row says:
     * the fix is an earlier statement, so the link opens the row's details
     * rather than its editor.
     */
    private const array DETAIL_CODES = ['fifo.unmatched_sell', 'fifo.option_unmatched_close'];

    public function __construct(
        public string $code,
        public DiagnosticLevel $level,
        public string $message,
        public string $targetTab,
        public ?string $rowId = null,
        public ?string $groupId = null,
    ) {
    }

    public static function blocking(
        string $code,
        string $message,
        string $targetTab,
        ?string $rowId = null,
        ?string $groupId = null,
    ): self {
        return new self($code, DiagnosticLevel::Blocking, $message, $targetTab, $rowId, $groupId);
    }

    public static function review(
        string $code,
        string $message,
        string $targetTab,
        ?string $rowId = null,
        ?string $groupId = null,
    ): self {
        return new self($code, DiagnosticLevel::Review, $message, $targetTab, $rowId, $groupId);
    }

    public function opensDetails(): bool
    {
        return in_array($this->code, self::DETAIL_CODES, true);
    }

    public function key(): string
    {
        return implode('|', [
            $this->level->value,
            $this->code,
            $this->message,
            $this->targetTab,
            $this->rowId ?? '',
            $this->groupId ?? '',
        ]);
    }
}
