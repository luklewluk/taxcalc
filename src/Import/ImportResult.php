<?php

declare(strict_types=1);

namespace App\Import;

use App\Import\Dto\TransactionTax;
use App\Model\ClosedPosition;
use App\Model\Dividend;
use App\Fifo\Trade;
use App\Model\AccountFee;

final readonly class ImportResult
{
    /**
     * @param list<ClosedPosition> $positions
     * @param list<Dividend>       $dividends
     * @param list<ImportMessage>  $messages
     * @param list<Trade>          $trades logical transactions entering FIFO
     * @param list<AccountFee>     $fees standalone account charges
     * @param list<TransactionTax> $transactionTaxes taxes on purchases, booked apart from the trade
     */
    public function __construct(
        public array $positions = [],
        public array $dividends = [],
        public array $messages = [],
        public array $trades = [],
        public array $fees = [],
        public array $transactionTaxes = [],
    ) {
    }

    public function merge(self $other): self
    {
        return new self(
            [...$this->positions, ...$other->positions],
            [...$this->dividends, ...$other->dividends],
            [...$this->messages, ...$other->messages],
            [...$this->trades, ...$other->trades],
            [...$this->fees, ...$other->fees],
            [...$this->transactionTaxes, ...$other->transactionTaxes],
        );
    }

    /**
     * @param list<ImportMessage> $messages
     */
    public function withMessages(array $messages): self
    {
        return new self(
            $this->positions,
            $this->dividends,
            [...$this->messages, ...$messages],
            $this->trades,
            $this->fees,
            $this->transactionTaxes,
        );
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
        // Review-level findings are warnings too as far as every text surface
        // is concerned - the level only decides whether the web panel gets an
        // item as well.
        return $this->describe(MessageLevel::Warning, MessageLevel::Review);
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
        return [] === $this->positions && [] === $this->dividends && [] === $this->trades && [] === $this->fees;
    }

    /**
     * @return list<string>
     */
    private function describe(MessageLevel ...$levels): array
    {
        $result = [];
        foreach ($this->messages as $message) {
            if (in_array($message->level, $levels, true)) {
                $result[] = $message->describe();
            }
        }

        return $result;
    }
}
