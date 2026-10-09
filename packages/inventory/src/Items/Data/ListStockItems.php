<?php

declare(strict_types=1);

namespace Commerz\Inventory\Items\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Inventory\Items\Enums\StockItemStatus;
use Commerz\Pagination\Cursor;

/** Items ordered by SKU, optionally of one status. */
#[Api(since: '0.1')]
final readonly class ListStockItems
{
    public function __construct(
        public ?StockItemStatus $status = null,
        public int $first = 100,
        public ?Cursor $after = null,
    ) {
        if ($first < 1 || $first > 200) {
            throw new InvalidCommand('first', 'must be from 1 to 200', 'common.invalid_page_size');
        }
    }
}
