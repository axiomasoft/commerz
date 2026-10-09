<?php

declare(strict_types=1);

namespace Commerz\Inventory\Locations\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

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
}
