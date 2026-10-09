<?php

declare(strict_types=1);

namespace Commerz\Inventory\Items\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** A stock item that still holds or reserves stock cannot be archived. */
#[Api(since: '0.1')]
final class StockItemNotEmpty extends CommerzException
{
    public function __construct(public readonly string $sku)
    {
        parent::__construct("Stock item '{$sku}' still holds or reserves stock.");
    }

    public function code(): string
    {
        return 'inventory.stock_item_not_empty';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['sku' => $this->sku];
    }
}
