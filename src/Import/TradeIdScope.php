<?php

declare(strict_types=1);

namespace App\Import;

/**
 * What a broker's transaction identifier actually identifies, which decides
 * what counts as a duplicated row and what counts as a contradiction.
 *
 * The distinction is not cosmetic. Treating an order ID as if it identified a
 * single execution would refuse every partially filled DEGIRO order as a
 * conflict; treating a fill ID as if it identified an order would let two
 * contradictory rows through as if they were parts of one trade.
 */
enum TradeIdScope
{
    /**
     * One identifier, one execution - Interactive Brokers' `TransactionID`.
     * The same ID with different content is a contradiction in the data.
     */
    case Fill;

    /**
     * One identifier, one *order*, which the exchange may fill in several rows -
     * DEGIRO's `Order ID`. Rows sharing an ID are parts of that order as long as
     * they agree on the instrument, currency and direction. Fills with the exact
     * same timestamp are aggregated; fills at different times remain separate.
     */
    case Order;
}
