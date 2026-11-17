<?php

declare(strict_types=1);

namespace Commerz\Sales\Laravel\Registrars;

use Commerz\Plugins\CommerzPlugins;
use Commerz\Sales\Carts\Contracts\Carts;
use Commerz\Sales\Carts\Internal\DatabaseCarts;
use Commerz\Sales\Checkout\Contracts\Checkout;
use Commerz\Sales\Checkout\Internal\OrderPlacement;
use Commerz\Sales\Configuration\SalesOptions;
use Commerz\Sales\Diagnostics\SalesDoctorCheck;
use Commerz\Sales\Fulfillments\Contracts\Fulfillments;
use Commerz\Sales\Fulfillments\Internal\DatabaseFulfillments;
use Commerz\Sales\Internal\Console\ExpireOrdersCommand;
use Commerz\Sales\Orders\Contracts\Orders;
use Commerz\Sales\Orders\Internal\DatabaseOrders;
use Commerz\Sales\Payments\Contracts\Payments;
use Commerz\Sales\Payments\Internal\DatabasePayments;
use Commerz\Sales\Plugins\SalesPlugin;
use Commerz\Sales\Refunds\Contracts\Refunds;
use Commerz\Sales\Refunds\Internal\DatabaseRefunds;
use Commerz\Sales\Returns\Contracts\Returns;
use Commerz\Sales\Returns\Internal\DatabaseReturns;
use Commerz\Sales\Sales;
use Illuminate\Console\Application as Artisan;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Application as FoundationApplication;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Extensions\Laravel\ExtensionFacetRegistrar;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\PackmanServiceProvider;
use Packman\Panels\Internal\Configuration\PanelsOptions;
use Packman\Panels\Laravel\PanelExtensionBridge;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Packman\Plugins\Laravel\PluginsServiceProvider;
use Packman\Schema\Laravel\SchemaExtensions;
use Packman\Schema\Laravel\SchemaServiceProvider;

/**
 * Wires Commerz sales services, extension plugin, options, schema ownership, diagnostics, and panels.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final class SalesRegistrar implements PackageRegistrar
{
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

        $app->singletonIf(SalesPlugin::class);

        $app->singleton(SalesDoctorCheck::class, static fn (Application $app): SalesDoctorCheck => new SalesDoctorCheck(
            config: $app->make(ConfigRepository::class),
            optionsResolver: static fn (): SalesOptions => $app->make(SalesOptions::class),
            contributionsResolver: static fn (): Contributions => $app->make(Contributions::class),
        ));

        if (! $app->bound('commerz.sales_facets_registered')) {
            $app->instance('commerz.sales_facets_registered', true);

            ExtensionFacetRegistrar::register(
                $app,
                SalesPlugin::class,
                dirname(__DIR__, 3).'/composer.json',
            );

            SchemaExtensions::register($app, SalesPlugin::class, SalesPlugin::ID);
        }

        if ($app->bound(PanelsOptions::class)) {
            PanelExtensionBridge::registerExtension($app, $app->make(SalesPlugin::class));
        } else {
            $app->afterResolving(PanelsOptions::class, static function (mixed $_, Application $app): void {
                PanelExtensionBridge::registerExtension($app, $app->make(SalesPlugin::class));
            });
        }

        $app->singleton(Carts::class, DatabaseCarts::class);
        $app->singleton(Checkout::class, OrderPlacement::class);
        $app->singleton(Orders::class, DatabaseOrders::class);
        $app->singleton(Payments::class, DatabasePayments::class);
        $app->singleton(Fulfillments::class, DatabaseFulfillments::class);
        $app->singleton(Returns::class, DatabaseReturns::class);
        $app->singleton(Refunds::class, DatabaseRefunds::class);
        $app->singleton(Sales::class);

        CommerzPlugins::builtIn(SalesPlugin::class);
    }

    #[Override]
    public function boot(Application $app): void
    {
        if ($app->runningInConsole()) {
            Artisan::starting(static function (Artisan $artisan): void {
                $artisan->resolveCommands([
                    ExpireOrdersCommand::class,
                ]);
            });
        }
    }
}
