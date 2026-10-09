<?php

declare(strict_types=1);

namespace Commerz\Inventory\Locations\Data;

use Commerz\Attributes\Api;
use Commerz\Inventory\Locations\Enums\LocationKind;

/** Renames a location or changes its kind; a field left null stays. The code never changes. */
#[Api(since: '0.1')]
final readonly class UpdateLocation
{
    public function __construct(
        public LocationId $location,
        public ?string $name = null,
        public ?LocationKind $kind = null,
    ) {
        if ($name !== null) {
            CreateLocation::assertName($name);
        }
    }
}
