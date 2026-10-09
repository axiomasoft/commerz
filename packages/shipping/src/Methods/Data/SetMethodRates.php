<?php

declare(strict_types=1);

namespace Commerz\Shipping\Methods\Data;

use Commerz\Attributes\Api;

/** Replaces all the tariffs of the method. */
#[Api(since: '0.1')]
final readonly class SetMethodRates
{
    /** @param  list<ZoneRate>  $rates  at most 500, one per zone and currency */
    public function __construct(public MethodId $id, public array $rates)
    {
        ZoneRate::assertValid($rates);
    }
}
