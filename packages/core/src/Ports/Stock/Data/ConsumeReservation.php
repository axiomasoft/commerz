<?php

declare(strict_types=1);

namespace Commerz\Ports\Stock\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;

#[Api(since: '0.1')]
final readonly class ConsumeReservation
{
    /** @param  string  $reference  the document that ships the stock, e.g. `fulfillment:01J…` */
    public function __construct(
        public string $demand,
        public SellableRef $sellable,
        public int $quantity,
        public string $reference,
    ) {
        StockLines::assertDemand($demand);

        if ($quantity < 1) {
            throw new InvalidCommand('quantity', 'must be at least 1', 'stock.invalid_quantity');
        }

        if (trim($reference) === '' || strlen($reference) > 191) {
            throw new InvalidCommand('reference', 'must be 1..191 bytes', 'stock.invalid_reference');
        }
    }
}
