<?php

declare(strict_types=1);

namespace Commerz\Pricing\Facades;

use Commerz\Pricing\Markets\Contracts\Markets;
use Commerz\Pricing\PriceLists\Contracts\PriceLists;
use Commerz\Pricing\Prices\Contracts\Prices;
use Commerz\Pricing\Prices\Contracts\StagedPrices;
use Commerz\Pricing\Taxes\Contracts\Taxes;
use Illuminate\Support\Facades\Facade;
use Packman\Attributes\Api;

/**
 * @method static PriceLists priceLists()
 * @method static Prices prices()
 * @method static StagedPrices stagedPrices()
 * @method static Taxes taxes()
 * @method static Markets markets()
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
