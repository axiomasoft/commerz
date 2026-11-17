<?php

declare(strict_types=1);

namespace Commerz\Inventory\Stock\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;
use Commerz\Inventory\Errors\Enums\InventoryErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

/** On-hand moved since the count started; recount from the current quantity instead of overwriting it. */
#[Api(since: '0.1')]
final class StaleStockCount extends CommerzException
{
    public function __construct(
        public readonly string $sku,
        public readonly string $location,
        public readonly int $expected,
        public readonly int $actual,
    ) {
        parent::__construct("Count of '{$sku}' at '{$location}' expected {$expected} on hand, found {$actual}.");
    }

    public function code(): string
    {
        return 'inventory.stale_stock_count';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['sku' => $this->sku, 'location' => $this->location, 'expected' => $this->expected, 'actual' => $this->actual];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: InventoryErrorCode::StaleStockCount,
            category: PackmanErrorCategory::Conflict,
            retryable: false,
            details: $this->context(),
        );
    }
}
