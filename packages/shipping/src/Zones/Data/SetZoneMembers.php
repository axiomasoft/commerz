<?php

declare(strict_types=1);

namespace Commerz\Shipping\Zones\Data;

use Commerz\Attributes\Api;

/** Replaces the whole member set of the zone. */
#[Api(since: '0.1')]
final readonly class SetZoneMembers
{
    /** @param  list<ZoneMember>  $members */
    public function __construct(public ZoneId $id, public array $members)
    {
        ZoneMember::assertUnique($members);
    }
}
