<?php

declare(strict_types=1);

namespace Commerz\Inventory\Items\Data;

use Packman\Attributes\Api;

/** Archives an item with no stock and no reservations; its links stay. Archiving twice changes nothing. */
#[Api(since: '0.1')]
final readonly class ArchiveStockItem
{
    public function __construct(public StockItemId $item) {}
}
