<?php

declare(strict_types=1);

namespace Commerz\Shipping\Packing\Data;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class PackingRequest
{
    /** @var non-empty-list<PackableItem> */
    public array $items;

    /** @param  list<PackableItem>  $items */
    public function __construct(
        array $items,
        public PackingConstraints $constraints = new PackingConstraints,
    ) {
        if ($items === []) {
            throw new InvalidCommand('items', 'must contain at least one packable item', 'shipping.invalid_packing');
        }

        $currency = $items[0]->totalValue->currency;

        foreach ($items as $item) {
            if (! $item->totalValue->currency->equals($currency)) {
                throw new InvalidCommand('items', 'all packable items must share the same currency', 'shipping.invalid_packing');
            }
        }

        $this->items = $items;
    }
}
