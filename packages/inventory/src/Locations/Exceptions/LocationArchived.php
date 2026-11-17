<?php

declare(strict_types=1);

namespace Commerz\Inventory\Locations\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;
use Commerz\Inventory\Errors\Enums\InventoryErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

/** The location is archived: restore it before changing it or placing stock there. */
#[Api(since: '0.1')]
final class LocationArchived extends CommerzException
{
    public function __construct(public readonly string $location)
    {
        parent::__construct("Location '{$location}' is archived.");
    }

    public function code(): string
    {
        return 'inventory.location_archived';
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
            code: InventoryErrorCode::LocationArchived,
            category: PackmanErrorCategory::Conflict,
            retryable: false,
            details: $this->context(),
        );
    }
}
