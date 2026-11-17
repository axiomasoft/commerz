<?php

declare(strict_types=1);

namespace Commerz\Shipping\Zones\Data;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class UpdateZone
{
    public function __construct(public ZoneId $id, public string $name)
    {
        CreateZone::assertName($name);
    }
}
