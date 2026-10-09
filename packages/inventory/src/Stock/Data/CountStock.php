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

/**
 * Sets on-hand to a counted quantity (movement `count` with the difference). Pass `expectedOnHand` — the on-hand the
 * counter started from — so a count never silently overwrites a sale made meanwhile: a mismatch fails as stale.
 */
#[Api(since: '0.1')]
final readonly class CountStock
{
    public function __construct(
        public StockItemId $item,
        public LocationId $location,
        public int $counted,
        public ?int $expectedOnHand = null,
        public ?string $reason = null,
        public ?string $reference = null,
        public ?ActorRef $actor = null,
        public ?IdempotencyKey $idempotencyKey = null,
    ) {
        if ($counted < 0) {
            throw new InvalidCommand('counted', 'must be zero or more', 'inventory.invalid_quantity');
        }

        if ($expectedOnHand !== null && $expectedOnHand < 0) {
            throw new InvalidCommand('expectedOnHand', 'must be zero or more', 'inventory.invalid_quantity');
        }

        if ($reason !== null) {
            InventoryCodes::reason($reason);
        }

        if ($reference !== null) {
            InventoryCodes::reference($reference);
        }
    }
}
