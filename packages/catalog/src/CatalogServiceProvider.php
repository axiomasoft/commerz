<?php

declare(strict_types=1);

namespace Commerz\Catalog;

use Commerz\Attributes\Api;
use Commerz\Catalog\Attributes\Contracts\Attributes;
use Commerz\Catalog\Attributes\Internal\AttributeIndexer;
use Commerz\Catalog\Attributes\Internal\DatabaseAttributes;
use Commerz\Catalog\Attributes\Internal\NoopAttributeIndexer;
use Commerz\Catalog\Common\Internal\CatalogConfig;
use Commerz\Catalog\Internal\CatalogPlugin;
use Commerz\Catalog\Options\Contracts\Options;
use Commerz\Catalog\Options\Internal\DatabaseOptions;
use Commerz\Catalog\ProductTypes\Contracts\ProductTypes;
use Commerz\Catalog\ProductTypes\Internal\DatabaseProductTypes;
use Commerz\Plugins\CommerzPlugins;
use Commerz\Schema\Contracts\SchemaOwnership;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

#[Api(since: '0.1')]
final class CatalogServiceProvider extends ServiceProvider
{
    public const array NOUNS = ['product', 'variant', 'option', 'attribute', 'category', 'listing'];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/commerz-catalog.php', 'commerz-catalog');

        $this->app->singleton(CatalogConfig::class, static function (Application $app): CatalogConfig {
            $config = $app->make(Repository::class)->get('commerz-catalog', []);

            return CatalogConfig::fromArray(is_array($config) ? $config : []);
        });

        $this->app->singleton(AttributeIndexer::class, NoopAttributeIndexer::class);
        $this->app->singleton(Attributes::class, DatabaseAttributes::class);
        $this->app->singleton(Options::class, DatabaseOptions::class);
        $this->app->singleton(ProductTypes::class, DatabaseProductTypes::class);
        $this->app->singleton(Catalog::class);

        CommerzPlugins::builtIn(CatalogPlugin::class);

        $this->callAfterResolving(SchemaOwnership::class, static function (SchemaOwnership $ownership): void {
            $ownership->declare('commerz.catalog', self::NOUNS);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/commerz-catalog.php' => config_path('commerz-catalog.php'),
            ], 'commerz-catalog-config');
        }
    }
}
