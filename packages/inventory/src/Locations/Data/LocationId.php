<?php

declare(strict_types=1);

namespace Commerz\Inventory\Locations\Data;

use Commerz\Attributes\Api;
use Commerz\Identity\EntityId;

#[Api(since: '0.1')]
final readonly class LocationId extends EntityId {}
