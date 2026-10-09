<?php

declare(strict_types=1);

namespace Commerz\Promotions;

use Commerz\Attributes\Api;
use Commerz\Plugins\CommerzPlugins;
use Commerz\Promotions\Common\Internal\PromotionsConfig;
use Commerz\Promotions\Internal\PromotionsPlugin;
use Commerz\Schema\Contracts\SchemaOwnership;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

#[Api(since: '0.1')]
final class PromotionsServiceProvider extends ServiceProvider
{
    public const array NOUNS = ['promotion', 'coupon'];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/commerz-promotions.php', 'commerz-promotions');

        $this->app->singleton(PromotionsConfig::class, static function (Application $app): PromotionsConfig {
            $config = $app->make(Repository::class)->get('commerz-promotions', []);

            return PromotionsConfig::fromArray(is_array($config) ? $config : []);
        });

        CommerzPlugins::builtIn(PromotionsPlugin::class);

        $this->callAfterResolving(SchemaOwnership::class, static function (SchemaOwnership $ownership): void {
            $ownership->declare('commerz.promotions', self::NOUNS);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/commerz-promotions.php' => config_path('commerz-promotions.php'),
            ], 'commerz-promotions-config');
        }
    }
}
