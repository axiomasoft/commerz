<?php

declare(strict_types=1);

namespace Commerz\Inventory\Items\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/**
 * An untracked item keeps no levels: stock cannot be placed on it, and the policy cannot change to it while stock is
 * held or reserved.
 */
#[Api(since: '0.1')]
final class UntrackedStockItem extends CommerzException
{
    public function __construct(public readonly string $sku, ?string $message = null)
    {
        parent::__construct($message ?? "Stock item '{$sku}' cannot become untracked while its levels hold or reserve stock.");
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
}
