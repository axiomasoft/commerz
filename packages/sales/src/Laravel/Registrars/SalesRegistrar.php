<?php

declare(strict_types=1);

namespace Commerz\Sales\Laravel\Registrars;

use Commerz\Sales\Carts\Contracts\Carts;
use Commerz\Sales\Carts\Internal\DatabaseCarts;
use Commerz\Sales\Checkout\Contracts\Checkout;
use Commerz\Sales\Checkout\Contracts\OrderSplitterPort;
use Commerz\Sales\Checkout\Internal\OrderPlacement;
use Commerz\Sales\Checkout\Internal\SingleOrderSplitter;
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
use Commerz\Schema\CommerzSchemaSets;
use Illuminate\Console\Events\ArtisanStarting;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Plugins\Contracts\Contributions\Contributions;

/**
 * Wires Commerz sales services, extension plugin, options, schema ownership, diagnostics, and panels.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final class SalesRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        $app->singletonIf(SalesPlugin::class);

        $app->singleton(SalesDoctorCheck::class, static fn (Application $app): SalesDoctorCheck => new SalesDoctorCheck(
            config: $app->make(ConfigRepository::class),
            optionsResolver: static fn (): SalesOptions => $app->make(SalesOptions::class),
            contributionsResolver: static fn (): Contributions => $app->make(Contributions::class),
        ));

        CommerzSchemaSets::governMigrations($app);

        $app->singleton(Carts::class, DatabaseCarts::class);
        $app->singletonIf(OrderSplitterPort::class, SingleOrderSplitter::class);
        $app->singleton(Checkout::class, OrderPlacement::class);
        $app->singleton(Orders::class, DatabaseOrders::class);
        $app->singleton(Payments::class, DatabasePayments::class);
        $app->singleton(Fulfillments::class, DatabaseFulfillments::class);
        $app->singleton(Returns::class, DatabaseReturns::class);
        $app->singleton(Refunds::class, DatabaseRefunds::class);
        $app->singleton(Sales::class);
    }

    #[Override]
    public function boot(Application $app): void
    {
        if ($app->runningInConsole()) {
            $app->make(Dispatcher::class)->listen(ArtisanStarting::class, static function (ArtisanStarting $event): void {
                $event->artisan->resolveCommands([
                    ExpireOrdersCommand::class,
                ]);
            });
        }
    }
}
