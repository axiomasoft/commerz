<?php

declare(strict_types=1);

namespace Commerz\Inventory\Locations\Data;

use Packman\Attributes\Api;

/** Makes an archived location active again. Restoring an active one changes nothing. */
#[Api(since: '0.1')]
final readonly class RestoreLocation
{
    public function __construct(public LocationId $location) {}
}
