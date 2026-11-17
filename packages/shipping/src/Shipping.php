<?php

declare(strict_types=1);

namespace Commerz\Shipping;

use Commerz\Shipping\Bookings\Contracts\Bookings;
use Commerz\Shipping\Methods\Contracts\Methods;
use Commerz\Shipping\Options\Contracts\ShippingOptions;
use Commerz\Shipping\Zones\Contracts\Zones;
use Packman\Attributes\Api;

/** Root of the shipping API: accessors of the module contracts. */
#[Api(since: '0.1')]
final readonly class Shipping
{
    public function __construct(
        private Zones $zones,
        private Methods $methods,
        private ShippingOptions $options,
        private Bookings $bookings,
    ) {}

    public function zones(): Zones
    {
        return $this->zones;
    }

    public function methods(): Methods
    {
        return $this->methods;
    }

    public function options(): ShippingOptions
    {
        return $this->options;
    }

    public function bookings(): Bookings
    {
        return $this->bookings;
    }
}
