<?php

declare(strict_types=1);

namespace Commerz\Inventory\Stock\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Identity\IdempotencyKey;
use Commerz\Inventory\Common\Data\InventoryCodes;
use Commerz\Inventory\Items\Data\StockItemId;
use Commerz\Inventory\Locations\Data\LocationId;

/** Puts received units on hand (movement `receipt`). */
#[Api(since: '0.1')]
final readonly class ReceiveStock
{
    public function __construct(
        public StockItemId $item,
        public LocationId $location,
        public int $quantity,
        public ?string $reference = null,
        public ?string $reason = null,
        public ?ActorRef $actor = null,
        public ?IdempotencyKey $idempotencyKey = null,
    ) {
        if ($quantity < 1) {
            throw new InvalidCommand('quantity', 'must be at least 1', 'inventory.invalid_quantity');
        }

        if ($reference !== null) {
            InventoryCodes::reference($reference);
        }

        if ($reason !== null) {
            InventoryCodes::reason($reason);
        }
    }
}
