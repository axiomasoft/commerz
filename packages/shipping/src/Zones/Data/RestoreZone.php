<?php

declare(strict_types=1);

namespace Commerz\Shipping\Zones\Data;

use Commerz\Attributes\Api;

#[Api(since: '0.1')]
final readonly class RestoreZone
{
    public function __construct(public ZoneId $id) {}
}
