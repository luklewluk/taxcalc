<?php

declare(strict_types=1);

namespace App\Import;

use App\Model\ClosedPosition;
use App\Model\Dividend;

final readonly class ImportResult
{
    /**
     * @param list<ClosedPosition> $positions
     * @param list<Dividend>       $dividends
     * @param list<ImportMessage>  $messages
     */
    public function __construct(
        public array $positions = [],
        public array $dividends = [],
        public array $messages = [],
    ) {
    }

    public function merge(self $other): self
    {
        return new self(
            [...$this->positions, ...$other->positions],
            [...$this->dividends, ...$other->dividends],
            [...$this->messages, ...$other->messages],
        );
    }

    /**
     * @param list<ImportMessage> $messages
     */
    public function withMessages(array $messages): self
    {
        return new self($this->positions, $this->dividends, [...$this->messages, ...$messages]);
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->describe(MessageLevel::Error);
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->describe(MessageLevel::Warning);
    }

    /**
     * @return list<string>
     */
    public function infos(): array
    {
        return $this->describe(MessageLevel::Info);
    }

    public function hasErrors(): bool
    {
        return [] !== $this->errors();
    }

    public function isEmpty(): bool
    {
        return [] === $this->positions && [] === $this->dividends;
    }

    /**
     * @return list<string>
     */
    private function describe(MessageLevel $level): array
    {
        $result = [];
        foreach ($this->messages as $message) {
            if ($message->level === $level) {
                $result[] = $message->describe();
            }
        }

        return $result;
    }
}
