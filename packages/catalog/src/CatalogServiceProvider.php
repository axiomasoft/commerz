<?php

declare(strict_types=1);

namespace Commerz\Catalog;

use Commerz\Attributes\Api;
use Commerz\Catalog\Common\Internal\CatalogConfig;
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
