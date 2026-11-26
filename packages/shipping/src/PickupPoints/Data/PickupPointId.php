<?php

declare(strict_types=1);

namespace Commerz\Shipping\PickupPoints\Data;

use Commerz\Identity\EntityId;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class PickupPointId extends EntityId {}
