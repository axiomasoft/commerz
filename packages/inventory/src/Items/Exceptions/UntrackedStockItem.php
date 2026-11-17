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

/**
 * An untracked item keeps no levels: stock cannot be placed on it, and the policy cannot change to it while stock is
 * held, reserved or unresolved in a transfer.
 */
#[Api(since: '0.1')]
final class UntrackedStockItem extends CommerzException
{
    public function __construct(public readonly string $sku, ?string $message = null)
    {
        parent::__construct($message ?? "Stock item '{$sku}' cannot become untracked while stock is held, reserved or unresolved in a transfer.");
    }

    /** Stock was placed on an untracked item. */
    public static function keepsNoLevels(string $sku): self
    {
        return new self($sku, "Stock item '{$sku}' is untracked and keeps no stock levels.");
    }

    public function code(): string
    {
        return 'inventory.untracked_stock_item';
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
            code: InventoryErrorCode::UntrackedStockItem,
            category: PackmanErrorCategory::Conflict,
            retryable: false,
            details: $this->context(),
        );
    }
}
