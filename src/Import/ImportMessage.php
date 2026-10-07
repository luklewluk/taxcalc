<?php

declare(strict_types=1);

namespace App\Import;

final readonly class ImportMessage
{
    private function __construct(
        public MessageLevel $level,
        public string $file,
        public ?int $line,
        public string $message,
        /** Explicit workbench destination; never inferred from message text. */
        public ?string $targetTab = null,
        /**
         * Machine-readable kind, for a finding the workbench raises again on
         * every recalculation (see {@see \App\Web\WorkbenchCalculator}); the
         * import screen then shows only the workbench's own item.
         */
        public ?string $code = null,
    ) {
    }

    public static function error(string $file, string $message, ?int $line = null): self
    {
        return new self(MessageLevel::Error, $file, $line, $message);
    }

    public static function warning(string $file, string $message, ?int $line = null): self
    {
        return new self(MessageLevel::Warning, $file, $line, $message);
    }

    /**
     * A non-fatal finding the user has to act on, which therefore earns a place
     * in the workbench's attention panel. Pair it with {@see forTab()} to say
     * where the user should go.
     */
    public static function review(string $file, string $message, ?int $line = null): self
    {
        return new self(MessageLevel::Review, $file, $line, $message);
    }

    public static function info(string $file, string $message, ?int $line = null): self
    {
        return new self(MessageLevel::Info, $file, $line, $message);
    }

    public function describe(): string
    {
        if (null === $this->line) {
            return sprintf('%s: %s', $this->file, $this->message);
        }

        return sprintf('%s (wiersz %d): %s', $this->file, $this->line, $this->message);
    }

    public function forTab(string $targetTab): self
    {
        return new self($this->level, $this->file, $this->line, $this->message, $targetTab, $this->code);
    }

    public function withCode(string $code): self
    {
        return new self($this->level, $this->file, $this->line, $this->message, $this->targetTab, $code);
    }
}
