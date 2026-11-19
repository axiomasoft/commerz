<?php

declare(strict_types=1);

namespace Commerz\Promotions\Laravel\Registrars;

use Commerz\Ports\Promotions\Contracts\PromotionUsage;
use Commerz\Promotions\Configuration\PromotionsOptions;
use Commerz\Promotions\Coupons\Contracts\Coupons;
use Commerz\Promotions\Coupons\Internal\DatabaseCoupons;
use Commerz\Promotions\Definitions\Contracts\PromotionDefinitions;
use Commerz\Promotions\Definitions\Internal\DatabasePromotionDefinitions;
use Commerz\Promotions\Definitions\Internal\PromotionRows;
use Commerz\Promotions\Definitions\Internal\UsageCounters;
use Commerz\Promotions\Diagnostics\PromotionsDoctorCheck;
use Commerz\Promotions\Evaluation\Internal\PromotionSessions;
use Commerz\Promotions\Internal\Console\ReconcilePromotionsCommand;
use Commerz\Promotions\Plugins\PromotionsPlugin;
use Commerz\Promotions\Promotions;
use Commerz\Promotions\Usage\Internal\DatabasePromotionUsage;
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
 * Wires Commerz promotions services, extension plugin, options, schema ownership, diagnostics, and panels.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final class PromotionsRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        $app->singletonIf(PromotionsPlugin::class);

        $app->singleton(PromotionsDoctorCheck::class, static fn (Application $app): PromotionsDoctorCheck => new PromotionsDoctorCheck(
            config: $app->make(ConfigRepository::class),
            optionsResolver: static fn (): PromotionsOptions => $app->make(PromotionsOptions::class),
            contributionsResolver: static fn (): Contributions => $app->make(Contributions::class),
        ));

        CommerzSchemaSets::governMigrations($app);

        $app->singleton(PromotionRows::class);
        $app->singleton(UsageCounters::class);
        $app->singleton(PromotionDefinitions::class, DatabasePromotionDefinitions::class);
        $app->singleton(Coupons::class, DatabaseCoupons::class);
        $app->singleton(Promotions::class);
        $app->singleton(PromotionSessions::class); // holds the quote sessions of the process
        $app->singleton(PromotionUsage::class, DatabasePromotionUsage::class); // replaces the core's singletonIf null default
    }

    #[Override]
    public function boot(Application $app): void
    {
        if ($app->runningInConsole()) {
            $app->make(Dispatcher::class)->listen(ArtisanStarting::class, static function (ArtisanStarting $event): void {
                $event->artisan->resolveCommands([
                    ReconcilePromotionsCommand::class,
                ]);
            });
        }
    }
}
