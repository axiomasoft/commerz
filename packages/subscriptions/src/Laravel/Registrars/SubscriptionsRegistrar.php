<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Laravel\Registrars;

use Commerz\Schema\CommerzSchemaSets;
use Commerz\Subscriptions\Billing\Contracts\BillingSchedule;
use Commerz\Subscriptions\Billing\Internal\Console\ProcessBillingCyclesCommand;
use Commerz\Subscriptions\Billing\Internal\DatabaseBillingSchedule;
use Commerz\Subscriptions\Configuration\SubscriptionsOptions;
use Commerz\Subscriptions\Diagnostics\SubscriptionsDoctorCheck;
use Commerz\Subscriptions\Lifecycle\Contracts\SubscriptionContracts;
use Commerz\Subscriptions\Lifecycle\Internal\DatabaseSubscriptionContracts;
use Commerz\Subscriptions\Lifecycle\Internal\OrderPlacedOutboxConsumer;
use Commerz\Subscriptions\Plugins\SubscriptionsPlugin;
use Commerz\Subscriptions\Renewals\Contracts\RenewalOrderCreator;
use Commerz\Subscriptions\Renewals\Contracts\RenewalOrders;
use Commerz\Subscriptions\Renewals\Internal\DatabaseRenewalOrders;
use Commerz\Subscriptions\Subscriptions;
use Illuminate\Console\Events\ArtisanStarting;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Contracts\PackageRegistrar;

/**
 * Wires Commerz subscriptions services, Outbox consumer, renewal SPI default, diagnostics, and console command.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final class SubscriptionsRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        $app->singletonIf(SubscriptionsPlugin::class);

        $app->singleton(SubscriptionsDoctorCheck::class, static fn (Application $app): SubscriptionsDoctorCheck => new SubscriptionsDoctorCheck(
            config: $app->make(ConfigRepository::class),
            optionsResolver: static fn (): SubscriptionsOptions => $app->make(SubscriptionsOptions::class),
        ));

        CommerzSchemaSets::governMigrations($app);

        $app->singleton(DatabaseRenewalOrders::class);
        $app->singletonIf(RenewalOrderCreator::class, DatabaseRenewalOrders::class);
        $app->singleton(RenewalOrders::class, DatabaseRenewalOrders::class);
        $app->singleton(SubscriptionContracts::class, DatabaseSubscriptionContracts::class);
        $app->singleton(BillingSchedule::class, DatabaseBillingSchedule::class);
        $app->singleton(OrderPlacedOutboxConsumer::class);
        $app->singleton(Subscriptions::class);
    }

    #[Override]
    public function boot(Application $app): void
    {
        if ($app->runningInConsole()) {
            $app->make(Dispatcher::class)->listen(ArtisanStarting::class, static function (ArtisanStarting $event): void {
                $event->artisan->resolveCommands([
                    ProcessBillingCyclesCommand::class,
                ]);
            });
        }
    }
}
