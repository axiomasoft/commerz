<?php

declare(strict_types=1);

namespace Commerz\Inventory\Items\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** An untracked item keeps no levels: the policy cannot change to it while stock is held or reserved. */
#[Api(since: '0.1')]
final class UntrackedStockItem extends CommerzException
{
    public function __construct(public readonly string $sku)
    {
        parent::__construct("Stock item '{$sku}' cannot become untracked while its levels hold or reserve stock.");
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
