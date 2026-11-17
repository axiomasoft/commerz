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

/** On-hand never goes below zero: the change would take more units than the level holds. */
#[Api(since: '0.1')]
final class NegativeStock extends CommerzException
{
    public function __construct(
        public readonly string $sku,
        public readonly string $location,
        public readonly int $onHand,
        public readonly int $delta,
    ) {
        parent::__construct("Stock of '{$sku}' at '{$location}' cannot change by {$delta}: only {$onHand} on hand.");
    }

    public function code(): string
    {
        return 'inventory.negative_stock';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['sku' => $this->sku, 'location' => $this->location, 'on_hand' => $this->onHand, 'delta' => $this->delta];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: InventoryErrorCode::NegativeStock,
            category: PackmanErrorCategory::Conflict,
            retryable: false,
            details: $this->context(),
        );
    }
}
