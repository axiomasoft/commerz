<?php

declare(strict_types=1);

namespace Commerz\Shipping\Facades;

use Commerz\Attributes\Api;
use Commerz\Shipping\Methods\Contracts\Methods;
use Commerz\Shipping\Options\Contracts\ShippingOptions;
use Commerz\Shipping\Zones\Contracts\Zones;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Zones zones()
 * @method static Methods methods()
 * @method static ShippingOptions options()
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
