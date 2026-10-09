<?php

declare(strict_types=1);

namespace Commerz\Inventory\Movements\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Inventory\Common\Data\InventoryCodes;
use Commerz\Inventory\Items\Data\StockItemId;
use Commerz\Inventory\Locations\Data\LocationId;
use Commerz\Pagination\Cursor;

/** Journal rows in the order they were written, optionally of one item, location and/or reference. */
#[Api(since: '0.1')]
final readonly class ListMovements
{
    public function __construct(
        public ?StockItemId $item = null,
        public ?LocationId $location = null,
        public ?string $reference = null,
        public int $first = 100,
        public ?Cursor $after = null,
    ) {
        if ($reference !== null) {
            InventoryCodes::reference($reference);
        }

        if ($first < 1 || $first > 500) {
            throw new InvalidCommand('first', 'must be from 1 to 500', 'common.invalid_page_size');
        }
    }
}
