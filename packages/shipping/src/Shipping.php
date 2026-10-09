<?php

declare(strict_types=1);

namespace Commerz\Shipping;

use Commerz\Attributes\Api;
use Commerz\Shipping\Methods\Contracts\Methods;
use Commerz\Shipping\Zones\Contracts\Zones;

/** Root of the shipping API: accessors of the module contracts. */
#[Api(since: '0.1')]
final readonly class Shipping
{
    public function __construct(
        private Zones $zones,
        private Methods $methods,
    ) {}

    public function zones(): Zones
    {
        return $this->zones;
    }

    public function methods(): Methods
    {
        return $this->methods;
    }
}
