<?php

declare(strict_types=1);

namespace Commerz\Shipping\Facades;

use Commerz\Shipping\Bookings\Contracts\Bookings;
use Commerz\Shipping\Methods\Contracts\Methods;
use Commerz\Shipping\Options\Contracts\ShippingOptions;
use Commerz\Shipping\Zones\Contracts\Zones;
use Illuminate\Support\Facades\Facade;
use Packman\Attributes\Api;

/**
 * @method static Zones zones()
 * @method static Methods methods()
 * @method static ShippingOptions options()
 * @method static Bookings bookings()
 *
 * @see \Commerz\Shipping\Shipping
 */
#[Api(since: '0.1')]
final class Shipping extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Commerz\Shipping\Shipping::class;
    }
}
