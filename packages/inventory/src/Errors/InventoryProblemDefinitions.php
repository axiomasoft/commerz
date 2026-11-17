<?php

declare(strict_types=1);

namespace Commerz\Inventory\Errors;

use Commerz\Inventory\Errors\Enums\InventoryErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;

/**
 * Registers RFC 9457 Problem Details definitions for all `axiomasoft/commerz-inventory` error codes.
 */
#[Api(since: '0.1')]
final readonly class InventoryProblemDefinitions implements ProblemDefinitionProvider
{
    public function __construct(
        private string $urnPrefix = 'urn:packman:problem:',
    ) {}

    /** @return list<ProblemDefinition> */
    #[Override]
    public function definitions(): array
    {
        return [
            $this->define(InventoryErrorCode::DuplicateCode, 409, 'Duplicate Inventory Code', ErrorCategory::Conflict, ['entity', 'code']),
            $this->define(InventoryErrorCode::InvalidAllocation, 422, 'Invalid Allocation Request', ErrorCategory::Validation, ['*']),
            $this->define(InventoryErrorCode::InvalidAllocationPlan, 503, 'Invalid Allocation Strategy Plan', ErrorCategory::Unavailable, ['reason']),
            $this->define(InventoryErrorCode::InvalidCode, 422, 'Invalid Inventory Code', ErrorCategory::Validation, ['*']),
            $this->define(InventoryErrorCode::InvalidFilter, 422, 'Invalid Stock Link Filter', ErrorCategory::Validation, ['*']),
            $this->define(InventoryErrorCode::InvalidLimit, 422, 'Invalid Expiry Batch Limit', ErrorCategory::Validation, ['*']),
            $this->define(InventoryErrorCode::InvalidPriority, 422, 'Invalid Stock Source Priority', ErrorCategory::Validation, ['*']),
            $this->define(InventoryErrorCode::InvalidQuantity, 422, 'Invalid Stock Quantity', ErrorCategory::Validation, ['*']),
            $this->define(InventoryErrorCode::InvalidReason, 422, 'Invalid Stock Movement Reason', ErrorCategory::Validation, ['*']),
            $this->define(InventoryErrorCode::InvalidReference, 422, 'Invalid Inventory Reference', ErrorCategory::Validation, ['*']),
            $this->define(InventoryErrorCode::InvalidSku, 422, 'Invalid Stock Item SKU', ErrorCategory::Validation, ['*']),
            $this->define(InventoryErrorCode::InvalidTransfer, 422, 'Invalid Stock Transfer', ErrorCategory::Validation, ['*']),
            $this->define(InventoryErrorCode::InvalidTransferState, 409, 'Invalid Transfer State', ErrorCategory::Conflict, ['transfer', 'state', 'action']),
            $this->define(InventoryErrorCode::LocationArchived, 409, 'Stock Location Archived', ErrorCategory::Conflict, ['location']),
            $this->define(InventoryErrorCode::LocationNotEmpty, 409, 'Stock Location Not Empty', ErrorCategory::Conflict, ['location']),
            $this->define(InventoryErrorCode::LocationNotFound, 404, 'Stock Location Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(InventoryErrorCode::NegativeStock, 409, 'Insufficient On-Hand Stock', ErrorCategory::Conflict, ['sku', 'location', 'on_hand', 'delta']),
            $this->define(InventoryErrorCode::NoStockSource, 422, 'No Stock Source Configured', ErrorCategory::Validation, ['*']),
            $this->define(InventoryErrorCode::ReservationConflict, 409, 'Reservation Channel Conflict', ErrorCategory::Conflict, ['demand', 'channel']),
            $this->define(InventoryErrorCode::StaleStockCount, 409, 'Stale Stock Count', ErrorCategory::Conflict, ['sku', 'location', 'expected', 'actual']),
            $this->define(InventoryErrorCode::StockItemArchived, 409, 'Stock Item Archived', ErrorCategory::Conflict, ['sku']),
            $this->define(InventoryErrorCode::StockItemNotEmpty, 409, 'Stock Item Not Empty', ErrorCategory::Conflict, ['sku']),
            $this->define(InventoryErrorCode::StockItemNotFound, 404, 'Stock Item Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(InventoryErrorCode::StockLinkMissing, 404, 'Sellable Stock Link Missing', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(InventoryErrorCode::TooManyComponents, 409, 'Too Many Kit Components', ErrorCategory::Conflict, ['sellable', 'max']),
            $this->define(InventoryErrorCode::TransferNotFound, 404, 'Stock Transfer Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(InventoryErrorCode::UntrackedStockItem, 409, 'Untracked Stock Item', ErrorCategory::Conflict, ['sku']),
        ];
    }

    /** @param list<string> $detailKeys */
    private function define(
        InventoryErrorCode $code,
        int $defaultStatus,
        string $title,
        ErrorCategory $category,
        array $detailKeys = [],
    ): ProblemDefinition {
        return new ProblemDefinition(
            code: $code,
            type: $this->urnPrefix.$code->value(),
            defaultStatus: $defaultStatus,
            title: $title,
            retryable: false,
            category: $category,
            detailKeys: $detailKeys,
        );
    }
}
