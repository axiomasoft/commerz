<?php

declare(strict_types=1);

namespace Commerz\Inventory\Locations\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\NotFound;

#[Api(since: '0.1')]
final class LocationNotFound extends NotFound
{
    /** @param  string  $reference  a location id or code */
    public function __construct(string $reference)
    {
        parent::__construct('Location', $reference, 'inventory.location_not_found');
    }
}
