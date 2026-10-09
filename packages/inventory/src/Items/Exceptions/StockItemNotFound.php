<?php

declare(strict_types=1);

namespace Commerz\Inventory\Items\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\NotFound;

#[Api(since: '0.1')]
final class StockItemNotFound extends NotFound
{
    /** @param  string  $reference  a stock item id or SKU */
    public function __construct(string $reference)
    {
        parent::__construct('Stock item', $reference, 'inventory.stock_item_not_found');
    }
}
