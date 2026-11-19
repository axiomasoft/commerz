<?php

declare(strict_types=1);

namespace Commerz\Pricing\Laravel\Registrars;

use Commerz\Plugins\CommerzPlugins;
use Commerz\Ports\Prices\Contracts\PriceResolver;
use Commerz\Pricing\Configuration\PricingOptions;
use Commerz\Pricing\Diagnostics\PricingDoctorCheck;
use Commerz\Pricing\Plugins\PricingPlugin;
use Commerz\Pricing\PriceLists\Contracts\PriceLists;
use Commerz\Pricing\PriceLists\Internal\DatabasePriceLists;
use Commerz\Pricing\Prices\Contracts\Prices;
use Commerz\Pricing\Prices\Internal\DatabasePriceResolver;
use Commerz\Pricing\Prices\Internal\DatabasePrices;
use Commerz\Pricing\Prices\Internal\PriceWrites;
use Commerz\Pricing\Pricing;
use Commerz\Pricing\Taxes\Contracts\Taxes;
use Commerz\Pricing\Taxes\Internal\DatabaseTaxes;
use Commerz\Pricing\Taxes\Internal\TaxRateLookup;
use Commerz\Pricing\Taxes\Internal\TaxZoneLookup;
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
 * Wires Commerz pricing services, extension plugin, options, schema ownership, diagnostics, and panels.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final class PricingRegistrar implements PackageRegistrar
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

        $app->singletonIf(PricingPlugin::class);

        $app->singleton(PricingDoctorCheck::class, static fn (Application $app): PricingDoctorCheck => new PricingDoctorCheck(
            config: $app->make(ConfigRepository::class),
            optionsResolver: static fn (): PricingOptions => $app->make(PricingOptions::class),
            contributionsResolver: static fn (): Contributions => $app->make(Contributions::class),
        ));

        if (! $app->bound('commerz.pricing_facets_registered')) {
            $app->instance('commerz.pricing_facets_registered', true);

            ExtensionFacetRegistrar::register(
                $app,
                PricingPlugin::class,
                dirname(__DIR__, 3).'/composer.json',
            );

            SchemaExtensions::register($app, PricingPlugin::class, PricingPlugin::ID);
        }

        CommerzSchemaSets::governMigrations($app);

        $app->singleton(PriceWrites::class);
        $app->singleton(PriceLists::class, DatabasePriceLists::class);
        // Replaces the core's snapshot default whichever provider registered first (the core binds it with `singletonIf`).
        $app->singleton(PriceResolver::class, DatabasePriceResolver::class);
        $app->singleton(Prices::class, DatabasePrices::class);
        $app->singleton(TaxZoneLookup::class);
        $app->singleton(TaxRateLookup::class);
        $app->singleton(Taxes::class, DatabaseTaxes::class);
        $app->singleton(Pricing::class);

        CommerzPlugins::builtInFor($app, PricingPlugin::class);
    }
}
