<?php

declare(strict_types=1);

namespace Commerz\Catalog\Facades;

use Commerz\Attributes\Api;
use Commerz\Catalog\Attributes\Contracts\Attributes;
use Commerz\Catalog\Options\Contracts\Options;
use Commerz\Catalog\Products\Contracts\Products;
use Commerz\Catalog\ProductTypes\Contracts\ProductTypes;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Products products()
 * @method static Options options()
 * @method static Attributes attributes()
 * @method static ProductTypes productTypes()
 *
 * @see \Commerz\Catalog\Catalog
 */
#[Api(since: '0.1')]
final class Catalog extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Commerz\Catalog\Catalog::class;
    }
}
