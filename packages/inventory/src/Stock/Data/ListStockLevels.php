<?php

declare(strict_types=1);

namespace Commerz\Inventory\Stock\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Inventory\Items\Data\StockItemId;
use Commerz\Inventory\Locations\Data\LocationId;
use Commerz\Pagination\Cursor;
use Packman\Attributes\Api;

/** Existing levels, optionally of one item and/or one location, in the order they were created. */
#[Api(since: '0.1')]
final readonly class ListStockLevels
{
    public function __construct(
        public ?StockItemId $item = null,
        public ?LocationId $location = null,
        public int $first = 100,
        public ?Cursor $after = null,
    ) {
        if ($first < 1 || $first > 500) {
            throw new InvalidCommand('first', 'must be from 1 to 500', 'common.invalid_page_size');
        }
    }
}
