<?php

declare(strict_types=1);

namespace Commerz\Catalog\Facades;

use Commerz\Catalog\Attributes\Contracts\Attributes;
use Commerz\Catalog\Brands\Contracts\Brands;
use Commerz\Catalog\Categories\Contracts\Categories;
use Commerz\Catalog\Collections\Contracts\Collections;
use Commerz\Catalog\Listings\Contracts\Listings;
use Commerz\Catalog\Media\Contracts\Media;
use Commerz\Catalog\Options\Contracts\Options;
use Commerz\Catalog\Products\Contracts\Products;
use Commerz\Catalog\ProductTypes\Contracts\ProductTypes;
use Commerz\Catalog\Projection\Contracts\StorefrontProjection;
use Commerz\Catalog\Relations\Contracts\ProductRelations;
use Commerz\Catalog\Routes\Contracts\UrlRoutes;
use Commerz\Catalog\Search\Contracts\CatalogSearchPort;
use Commerz\Catalog\Variants\Contracts\Variants;
use Illuminate\Support\Facades\Facade;
use Packman\Attributes\Api;

/**
 * @method static Products products()
 * @method static Variants variants()
 * @method static Options options()
 * @method static Attributes attributes()
 * @method static ProductTypes productTypes()
 * @method static Categories categories()
 * @method static Collections collections()
 * @method static Listings listings()
 * @method static Brands brands()
 * @method static ProductRelations relations()
 * @method static Media media()
 * @method static UrlRoutes routes()
 * @method static CatalogSearchPort search()
 * @method static StorefrontProjection storefront()
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
