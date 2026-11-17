<?php

declare(strict_types=1);

namespace Commerz\Inventory\Items\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Pagination\Cursor;
use Packman\Attributes\Api;

/** Links of one sellable, of one item, or of both; at least one must be given. Ordered by link id. */
#[Api(since: '0.1')]
final readonly class ListLinks
{
    public function __construct(
        public ?SellableRef $sellable = null,
        public ?StockItemId $item = null,
        public int $first = 100,
        public ?Cursor $after = null,
    ) {
        if (! $sellable instanceof SellableRef && ! $item instanceof StockItemId) {
            throw new InvalidCommand('sellable', 'give a sellable, an item or both', 'inventory.invalid_filter');
        }

        if ($first < 1 || $first > 200) {
            throw new InvalidCommand('first', 'must be from 1 to 200', 'common.invalid_page_size');
        }
    }
}
