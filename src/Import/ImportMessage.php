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
}
