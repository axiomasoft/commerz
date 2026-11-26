<?php

declare(strict_types=1);

namespace Commerz\Catalog;

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
use Packman\Attributes\Api;

/** Root of the catalog API: accessors of the module contracts. */
#[Api(since: '0.1')]
final readonly class Catalog
{
    public function __construct(
        private Options $options,
        private Attributes $attributes,
        private ProductTypes $productTypes,
        private Products $products,
        private Variants $variants,
        private Categories $categories,
        private Listings $listings,
        private Brands $brands,
        private ProductRelations $relations,
        private Media $media,
        private UrlRoutes $routes,
        private CatalogSearchPort $search,
        private Collections $collections,
        private StorefrontProjection $storefront,
    ) {}

    public function products(): Products
    {
        return $this->products;
    }

    public function variants(): Variants
    {
        return $this->variants;
    }

    public function options(): Options
    {
        return $this->options;
    }

    public function attributes(): Attributes
    {
        return $this->attributes;
    }

    public function productTypes(): ProductTypes
    {
        return $this->productTypes;
    }

    public function categories(): Categories
    {
        return $this->categories;
    }

    public function listings(): Listings
    {
        return $this->listings;
    }

    public function brands(): Brands
    {
        return $this->brands;
    }

    public function relations(): ProductRelations
    {
        return $this->relations;
    }

    public function media(): Media
    {
        return $this->media;
    }

    public function routes(): UrlRoutes
    {
        return $this->routes;
    }

    public function search(): CatalogSearchPort
    {
        return $this->search;
    }

    public function collections(): Collections
    {
        return $this->collections;
    }

    public function storefront(): StorefrontProjection
    {
        return $this->storefront;
    }
}
