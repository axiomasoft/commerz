<?php

declare(strict_types=1);

namespace Commerz\Catalog\Laravel\Registrars;

use Commerz\Catalog\Attributes\Contracts\Attributes;
use Commerz\Catalog\Attributes\Internal\AttributeIndexer;
use Commerz\Catalog\Attributes\Internal\AttributeWriter;
use Commerz\Catalog\Attributes\Internal\DatabaseAttributes;
use Commerz\Catalog\Catalog;
use Commerz\Catalog\Categories\Contracts\Categories;
use Commerz\Catalog\Categories\Internal\DatabaseCategories;
use Commerz\Catalog\Configuration\CatalogOptions;
use Commerz\Catalog\Diagnostics\CatalogDoctorCheck;
use Commerz\Catalog\Listings\Contracts\Listings;
use Commerz\Catalog\Listings\Internal\DatabaseListings;
use Commerz\Catalog\Options\Contracts\Options;
use Commerz\Catalog\Options\Internal\DatabaseOptions;
use Commerz\Catalog\Plugins\CatalogPlugin;
use Commerz\Catalog\Products\Contracts\Products;
use Commerz\Catalog\Products\Internal\DatabaseProducts;
use Commerz\Catalog\ProductTypes\Contracts\ProductTypes;
use Commerz\Catalog\ProductTypes\Internal\DatabaseProductTypes;
use Commerz\Catalog\Variants\Contracts\Variants;
use Commerz\Catalog\Variants\Internal\DatabaseVariants;
use Commerz\Plugins\CommerzPlugins;
use Commerz\Schema\CommerzSchemaSets;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Application as FoundationApplication;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Extensions\Laravel\ExtensionFacetRegistrar;
use Packman\Foundation\Concerns\RegistersNothingOnBoot;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\PackmanServiceProvider;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Packman\Plugins\Laravel\PluginsServiceProvider;
use Packman\Schema\Laravel\SchemaExtensions;
use Packman\Schema\Laravel\SchemaServiceProvider;

/**
 * Wires Commerz catalog services, extension plugin, options, schema ownership, diagnostics, and panels.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final class CatalogRegistrar implements PackageRegistrar
{
    use RegistersNothingOnBoot;

    #[Override]
    public function register(Application $app): void
    {
        foreach ([
            PackmanServiceProvider::class,
            PluginsServiceProvider::class,
            SchemaServiceProvider::class,
        ] as $provider) {
            if ($app instanceof FoundationApplication && $app->getProvider($provider) === null) {
                $app->register($provider);
            }
        }

        $app->singletonIf(CatalogPlugin::class);

        $app->singleton(CatalogDoctorCheck::class, static fn (Application $app): CatalogDoctorCheck => new CatalogDoctorCheck(
            config: $app->make(ConfigRepository::class),
            optionsResolver: static fn (): CatalogOptions => $app->make(CatalogOptions::class),
            contributionsResolver: static fn (): Contributions => $app->make(Contributions::class),
        ));

        if (! $app->bound('commerz.catalog_facets_registered')) {
            $app->instance('commerz.catalog_facets_registered', true);

            ExtensionFacetRegistrar::register(
                $app,
                CatalogPlugin::class,
                dirname(__DIR__, 3).'/composer.json',
            );

            SchemaExtensions::register($app, CatalogPlugin::class, CatalogPlugin::ID);
        }

        CommerzSchemaSets::governMigrations($app);

        $app->singleton(AttributeWriter::class);
        $app->singleton(AttributeIndexer::class, AttributeWriter::class);
        $app->singleton(Attributes::class, DatabaseAttributes::class);
        $app->singleton(Options::class, DatabaseOptions::class);
        $app->singleton(ProductTypes::class, DatabaseProductTypes::class);
        $app->singleton(Products::class, DatabaseProducts::class);
        $app->singleton(Variants::class, DatabaseVariants::class);
        $app->singleton(Categories::class, DatabaseCategories::class);
        $app->singleton(Listings::class, DatabaseListings::class);
        $app->singleton(Catalog::class);

        CommerzPlugins::builtInFor($app, CatalogPlugin::class);
    }
}
