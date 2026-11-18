<?php

declare(strict_types=1);

namespace Commerz\Inventory\Items\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Inventory\Errors\Enums\InventoryErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** A stock item that holds, reserves or has unresolved goods in a transfer cannot be archived. */
#[Api(since: '0.1')]
final class StockItemNotEmpty extends CommerzException
{
    public function __construct(public readonly string $sku)
    {
        parent::__construct("Stock item '{$sku}' still holds or reserves stock, or has an unresolved transfer.");
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

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: InventoryErrorCode::StockItemNotEmpty,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
