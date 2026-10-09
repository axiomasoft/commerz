<?php

declare(strict_types=1);

namespace Commerz\Inventory\Locations\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

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
}
