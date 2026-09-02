<?php

declare(strict_types=1);

namespace App\Web;

/**
 * A machine-addressable workbench problem. Navigation is explicit metadata;
 * the UI never guesses a destination by searching the Polish message text.
 */
final readonly class Diagnostic
{
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
