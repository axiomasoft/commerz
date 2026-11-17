<?php

declare(strict_types=1);

namespace Commerz\Inventory\Locations\Data;

use Packman\Attributes\Api;

/** Archives an empty location without open transfers. Archiving twice changes nothing. */
#[Api(since: '0.1')]
final readonly class ArchiveLocation
{
    public function __construct(public LocationId $location) {}
}
