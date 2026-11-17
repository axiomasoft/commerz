<?php

declare(strict_types=1);

namespace Commerz\Inventory\Items\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Packman\Attributes\Api;

/**
 * Says that one unit of the sellable takes `quantityPerUnit` of the item. A sellable with several links is a kit;
 * linking an existing pair updates its quantity.
 */
#[Api(since: '0.1')]
final readonly class LinkSellable
{
    public function __construct(
        public SellableRef $sellable,
        public StockItemId $item,
        public int $quantityPerUnit = 1,
    ) {
        if ($quantityPerUnit < 1) {
            throw new InvalidCommand('quantityPerUnit', 'must be positive', 'inventory.invalid_quantity');
        }
    }
}
