<?php

declare(strict_types=1);

namespace Commerz\Sales;

use Commerz\Attributes\Api;
use Commerz\Plugins\CommerzPlugins;
use Commerz\Sales\Carts\Contracts\Carts;
use Commerz\Sales\Carts\Internal\DatabaseCarts;
use Commerz\Sales\Checkout\Contracts\Checkout;
use Commerz\Sales\Checkout\Internal\OrderPlacement;
use Commerz\Sales\Common\Internal\SalesConfig;
use Commerz\Sales\Internal\Console\ExpireOrdersCommand;
use Commerz\Sales\Internal\SalesPlugin;
use Commerz\Sales\Orders\Contracts\Orders;
use Commerz\Sales\Orders\Internal\DatabaseOrders;
use Commerz\Sales\Payments\Contracts\Payments;
use Commerz\Sales\Payments\Internal\DatabasePayments;
use Commerz\Schema\Contracts\SchemaOwnership;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

#[Api(since: '0.1')]
final class SalesServiceProvider extends ServiceProvider
{
    public const array NOUNS = ['cart', 'checkout', 'order', 'payment', 'fulfillment', 'return', 'refund'];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/commerz-sales.php', 'commerz-sales');

        $this->app->singleton(SalesConfig::class, static function (Application $app): SalesConfig {
            $config = $app->make(Repository::class)->get('commerz-sales', []);

            return SalesConfig::fromArray(is_array($config) ? $config : []);
        });

        $this->app->singleton(Carts::class, DatabaseCarts::class);
        $this->app->singleton(Checkout::class, OrderPlacement::class);
        $this->app->singleton(Orders::class, DatabaseOrders::class);
        $this->app->singleton(Payments::class, DatabasePayments::class);
        $this->app->singleton(Sales::class);

        CommerzPlugins::builtIn(SalesPlugin::class);

        $this->callAfterResolving(SchemaOwnership::class, static function (SchemaOwnership $ownership): void {
            $ownership->declare('commerz.sales', self::NOUNS);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([ExpireOrdersCommand::class]);
            $this->publishes([
                __DIR__.'/../config/commerz-sales.php' => config_path('commerz-sales.php'),
            ], 'commerz-sales-config');
        }
    }
}
