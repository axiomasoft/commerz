<?php

declare(strict_types=1);

namespace Commerz\Inventory\Locations\Data;

use Commerz\Identity\EntityId;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class LocationId extends EntityId {}
