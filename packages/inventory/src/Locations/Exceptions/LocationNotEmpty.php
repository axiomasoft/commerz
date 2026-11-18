<?php

declare(strict_types=1);

namespace Commerz\Inventory\Locations\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Inventory\Errors\Enums\InventoryErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** A location that still holds or reserves stock, or has a draft or in-transit transfer, cannot be archived. */
#[Api(since: '0.1')]
final class LocationNotEmpty extends CommerzException
{
    public function __construct(public readonly string $location)
    {
        parent::__construct("Location '{$location}' still holds stock, reservations or open transfers.");
    }

    public function code(): string
    {
        return 'inventory.location_not_empty';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['location' => $this->location];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: InventoryErrorCode::LocationNotEmpty,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->context(),
        );
    }
}
