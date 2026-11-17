<?php

declare(strict_types=1);

namespace Commerz\Inventory\Errors\Enums;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;

/**
 * Stable wire-level error codes emitted by `axiomasoft/commerz-inventory`.
 */
#[Api(since: '0.1')]
enum InventoryErrorCode: string implements ErrorCode
{
    case DuplicateCode = 'inventory.duplicate_code';
    case InvalidAllocation = 'inventory.invalid_allocation';
    case InvalidAllocationPlan = 'inventory.invalid_allocation_plan';
    case InvalidCode = 'inventory.invalid_code';
    case InvalidFilter = 'inventory.invalid_filter';
    case InvalidLimit = 'inventory.invalid_limit';
    case InvalidPriority = 'inventory.invalid_priority';
    case InvalidQuantity = 'inventory.invalid_quantity';
    case InvalidReason = 'inventory.invalid_reason';
    case InvalidReference = 'inventory.invalid_reference';
    case InvalidSku = 'inventory.invalid_sku';
    case InvalidTransfer = 'inventory.invalid_transfer';
    case InvalidTransferState = 'inventory.invalid_transfer_state';
    case LocationArchived = 'inventory.location_archived';
    case LocationNotEmpty = 'inventory.location_not_empty';
    case LocationNotFound = 'inventory.location_not_found';
    case NegativeStock = 'inventory.negative_stock';
    case NoStockSource = 'inventory.no_stock_source';
    case ReservationConflict = 'inventory.reservation_conflict';
    case StaleStockCount = 'inventory.stale_stock_count';
    case StockItemArchived = 'inventory.stock_item_archived';
    case StockItemNotEmpty = 'inventory.stock_item_not_empty';
    case StockItemNotFound = 'inventory.stock_item_not_found';
    case StockLinkMissing = 'inventory.stock_link_missing';
    case TooManyComponents = 'inventory.too_many_components';
    case TransferNotFound = 'inventory.transfer_not_found';
    case UntrackedStockItem = 'inventory.untracked_stock_item';

    #[Override]
    public function value(): string
    {
        return $this->value;
    }
}
