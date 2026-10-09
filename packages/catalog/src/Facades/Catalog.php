<?php

declare(strict_types=1);

namespace Commerz\Catalog\Facades;

use Commerz\Attributes\Api;
use Commerz\Catalog\Attributes\Contracts\Attributes;
use Commerz\Catalog\Categories\Contracts\Categories;
use Commerz\Catalog\Listings\Contracts\Listings;
use Commerz\Catalog\Options\Contracts\Options;
use Commerz\Catalog\Products\Contracts\Products;
use Commerz\Catalog\ProductTypes\Contracts\ProductTypes;
use Commerz\Catalog\Variants\Contracts\Variants;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Products products()
 * @method static Variants variants()
 * @method static Options options()
 * @method static Attributes attributes()
 * @method static ProductTypes productTypes()
 * @method static Categories categories()
 * @method static Listings listings()
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
