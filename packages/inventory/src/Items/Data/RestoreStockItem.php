<?php

declare(strict_types=1);

namespace Commerz\Inventory\Items\Data;

use Commerz\Attributes\Api;

/** Makes an archived item active again. Restoring an active one changes nothing. */
#[Api(since: '0.1')]
final readonly class RestoreStockItem
{
    public function __construct(public StockItemId $item) {}
}
