<?php

declare(strict_types=1);

namespace Commerz\Inventory\Stock\Exceptions;

use Commerz\Errors\NotFound;
use Commerz\Inventory\Errors\Enums\InventoryErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** The sellable is linked to no stock item, and `commerz-inventory.unlinked_sellables` is `unavailable`. */
#[Api(since: '0.1')]
final class StockLinkMissing extends NotFound
{
    public function __construct(string $sellable)
    {
        parent::__construct('Stock link', $sellable, 'inventory.stock_link_missing');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: InventoryErrorCode::StockLinkMissing,
            category: ErrorCategory::NotFound,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
