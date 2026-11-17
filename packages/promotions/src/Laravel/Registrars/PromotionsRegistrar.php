<?php

declare(strict_types=1);

namespace Commerz\Promotions\Laravel\Registrars;

use Commerz\Plugins\CommerzPlugins;
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
 * Wires Commerz promotions services, extension plugin, options, schema ownership, diagnostics, and panels.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final class PromotionsRegistrar implements PackageRegistrar
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

        $app->singletonIf(PromotionsPlugin::class);

        $app->singleton(PromotionsDoctorCheck::class, static fn (Application $app): PromotionsDoctorCheck => new PromotionsDoctorCheck(
            config: $app->make(ConfigRepository::class),
            optionsResolver: static fn (): PromotionsOptions => $app->make(PromotionsOptions::class),
            contributionsResolver: static fn (): Contributions => $app->make(Contributions::class),
        ));

        if (! $app->bound('commerz.promotions_facets_registered')) {
            $app->instance('commerz.promotions_facets_registered', true);

            ExtensionFacetRegistrar::register(
                $app,
                PromotionsPlugin::class,
                dirname(__DIR__, 3).'/composer.json',
            );

            SchemaExtensions::register($app, PromotionsPlugin::class, PromotionsPlugin::ID);
        }

        if ($app->bound(PanelsOptions::class)) {
            PanelExtensionBridge::registerExtension($app, $app->make(PromotionsPlugin::class));
        } else {
            $app->afterResolving(PanelsOptions::class, static function (mixed $_, Application $app): void {
                PanelExtensionBridge::registerExtension($app, $app->make(PromotionsPlugin::class));
            });
        }

        $app->singleton(PromotionRows::class);
        $app->singleton(UsageCounters::class);
        $app->singleton(PromotionDefinitions::class, DatabasePromotionDefinitions::class);
        $app->singleton(Coupons::class, DatabaseCoupons::class);
        $app->singleton(Promotions::class);
        $app->singleton(PromotionSessions::class); // holds the quote sessions of the process
        $app->singleton(PromotionUsage::class, DatabasePromotionUsage::class); // replaces the core's singletonIf null default

        CommerzPlugins::builtIn(PromotionsPlugin::class);
    }

    #[Override]
    public function boot(Application $app): void
    {
        if ($app->runningInConsole()) {
            Artisan::starting(static function (Artisan $artisan): void {
                $artisan->resolveCommands([
                    ReconcilePromotionsCommand::class,
                ]);
            });
        }
    }
}
