<?php

declare(strict_types=1);

namespace App\Fifo;

use App\Money\Amount;
use App\Money\Decimal;

/**
 * What is left of one sale while its lots are consumed - the sale-side twin of
 * {@see OpenLot}, so FIFO and named lots prorate the proceeds the same way.
 *
 * @internal
 */
final class SaleProgress
{
    public Decimal $quantityLeft;

    public readonly Decimal $quantityTotal;

    public Amount $amountLeft;

    public ?Amount $commissionLeft;

    public ?Amount $autoFxLeft;

    public function __construct(public readonly Trade $trade)
    {
        $this->quantityLeft = $trade->quantity->abs();
        $this->quantityTotal = $this->quantityLeft;
        $this->amountLeft = $trade->grossAmount->abs();
        $this->commissionLeft = $trade->commission?->abs();
        $this->autoFxLeft = $trade->autoFx?->abs();
    }
}
