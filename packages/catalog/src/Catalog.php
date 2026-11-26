<?php

declare(strict_types=1);

namespace Commerz\Catalog;

use Commerz\Catalog\Attributes\Contracts\Attributes;
use Commerz\Catalog\Categories\Contracts\Categories;
use Commerz\Catalog\Collections\Contracts\Collections;
use Commerz\Catalog\Listings\Contracts\Listings;
use Commerz\Catalog\Options\Contracts\Options;
use Commerz\Catalog\Products\Contracts\Products;
use Commerz\Catalog\ProductTypes\Contracts\ProductTypes;
use Commerz\Catalog\Projection\Contracts\StorefrontProjection;
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

    public function collections(): Collections
    {
        return $this->collections;
    }

    public function storefront(): StorefrontProjection
    {
        return $this->storefront;
    }
}
