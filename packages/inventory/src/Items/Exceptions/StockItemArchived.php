<?php

declare(strict_types=1);

namespace Commerz\Inventory\Items\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;
use Commerz\Inventory\Errors\Enums\InventoryErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

/** The item is archived: restore it before changing it, linking it or placing stock. */
#[Api(since: '0.1')]
final class StockItemArchived extends CommerzException
{
    public function __construct(public readonly string $sku)
    {
        parent::__construct("Stock item '{$sku}' is archived.");
    }

    public function code(): string
    {
        return 'inventory.stock_item_archived';
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
            code: InventoryErrorCode::StockItemArchived,
            category: PackmanErrorCategory::Conflict,
            retryable: false,
            details: $this->context(),
        );
    }
}
