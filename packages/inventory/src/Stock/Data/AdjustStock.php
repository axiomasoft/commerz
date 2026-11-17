<?php

declare(strict_types=1);

namespace Commerz\Inventory\Stock\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Identity\IdempotencyKey;
use Commerz\Inventory\Common\Data\InventoryCodes;
use Commerz\Inventory\Items\Data\StockItemId;
use Commerz\Inventory\Locations\Data\LocationId;
use Packman\Attributes\Api;

/** Changes on-hand by a signed delta with a mandatory reason (movement `adjustment`), e.g. damage or a found unit. */
#[Api(since: '0.1')]
final readonly class AdjustStock
{
    public function __construct(
        public StockItemId $item,
        public LocationId $location,
        public int $delta,
        public string $reason,
        public ?string $reference = null,
        public ?ActorRef $actor = null,
        public ?IdempotencyKey $idempotencyKey = null,
    ) {
        if ($delta === 0 || $delta === PHP_INT_MIN) {
            throw new InvalidCommand('delta', 'must be a non-zero int64', 'inventory.invalid_quantity');
        }

        InventoryCodes::reason($reason);

        if ($reference !== null) {
            InventoryCodes::reference($reference);
        }
    }
}
