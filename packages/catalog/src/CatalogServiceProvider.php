<?php

declare(strict_types=1);

namespace Commerz\Catalog;

use Commerz\Catalog\Laravel\Registrars\CatalogRegistrar;
use Commerz\Catalog\Plugins\CatalogPlugin;
use Commerz\Laravel\CommerzPluginServiceProvider;
use Packman\Attributes\Api;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Api(since: '0.1')]
final class CatalogServiceProvider extends CommerzPluginServiceProvider
{
    protected const string PLUGIN = CatalogPlugin::class;

    public const array NOUNS = ['product', 'variant', 'option', 'attribute', 'category', 'listing', 'brand', 'asset_attachment', 'url_route', 'collection', 'storefront_product'];

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        CatalogRegistrar::class,
    ];
}
