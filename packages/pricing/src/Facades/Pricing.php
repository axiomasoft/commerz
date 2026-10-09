<?php

declare(strict_types=1);

namespace Commerz\Pricing\Facades;

use Commerz\Attributes\Api;
use Commerz\Pricing\PriceLists\Contracts\PriceLists;
use Commerz\Pricing\Prices\Contracts\Prices;
use Illuminate\Support\Facades\Facade;

/**
 * @method static PriceLists priceLists()
 * @method static Prices prices()
 *
 * @see \Commerz\Pricing\Pricing
 */
#[Api(since: '0.1')]
final class Pricing extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Commerz\Pricing\Pricing::class;
    }
}
