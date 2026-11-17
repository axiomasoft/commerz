<?php

declare(strict_types=1);

namespace Commerz\Shipping\Zones\Events;

use Commerz\Shipping\Zones\Data\ZoneId;
use Packman\Attributes\Api;

/** A zone was created, renamed, had its members replaced, was archived or restored in a committed operation. */
#[Api(since: '0.1')]
final readonly class ZoneChanged
{
    public function __construct(public ZoneId $zone) {}
}
