<?php

declare(strict_types=1);

namespace Commerz\Inventory\Items\Exceptions;

use Commerz\Errors\NotFound;
use Commerz\Inventory\Errors\Enums\InventoryErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

#[Api(since: '0.1')]
final class StockItemNotFound extends NotFound
{
    /** @param  string  $reference  a stock item id or SKU */
    public function __construct(string $reference)
    {
        parent::__construct('Stock item', $reference, 'inventory.stock_item_not_found');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: InventoryErrorCode::StockItemNotFound,
            category: PackmanErrorCategory::NotFound,
            retryable: false,
            details: $this->context(),
        );
    }
}
