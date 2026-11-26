<?php

declare(strict_types=1);

namespace Commerz\Pricing\Laravel\Registrars;

use Commerz\Ports\Prices\Contracts\PriceResolver;
use Commerz\Ports\Taxes\Contracts\TaxEngine;
use Commerz\Pricing\Configuration\PricingOptions;
use Commerz\Pricing\Diagnostics\PricingDoctorCheck;
use Commerz\Pricing\Markets\Contracts\Markets;
use Commerz\Pricing\Markets\Internal\DatabaseMarkets;
use Commerz\Pricing\Plugins\PricingPlugin;
use Commerz\Pricing\PriceLists\Contracts\PriceLists;
use Commerz\Pricing\PriceLists\Internal\DatabasePriceLists;
use Commerz\Pricing\Prices\Contracts\Prices;
use Commerz\Pricing\Prices\Contracts\StagedPrices;
use Commerz\Pricing\Prices\Internal\DatabasePriceResolver;
use Commerz\Pricing\Prices\Internal\DatabasePrices;
use Commerz\Pricing\Prices\Internal\DatabaseStagedPrices;
use Commerz\Pricing\Prices\Internal\PriceCandidates;
use Commerz\Pricing\Prices\Internal\PriceHistoryJournal;
use Commerz\Pricing\Prices\Internal\PriceWrites;
use Commerz\Pricing\Prices\Internal\StagedPriceOverlay;
use Commerz\Pricing\Pricing;
use Commerz\Pricing\Taxes\Contracts\Taxes;
use Commerz\Pricing\Taxes\Internal\DatabaseTaxes;
use Commerz\Pricing\Taxes\Internal\PricingTaxEngine;
use Commerz\Pricing\Taxes\Internal\TaxRateLookup;
use Commerz\Pricing\Taxes\Internal\TaxZoneLookup;
use Commerz\Schema\CommerzSchemaSets;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Concerns\RegistersNothingOnBoot;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Plugins\Contracts\Contributions\Contributions;

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
        $app->singletonIf(PricingPlugin::class);

        $app->singleton(PricingDoctorCheck::class, static fn (Application $app): PricingDoctorCheck => new PricingDoctorCheck(
            config: $app->make(ConfigRepository::class),
            optionsResolver: static fn (): PricingOptions => $app->make(PricingOptions::class),
            contributionsResolver: static fn (): Contributions => $app->make(Contributions::class),
        ));

        CommerzSchemaSets::governMigrations($app);

        $app->singleton(StagedPriceOverlay::class);
        $app->singleton(PriceHistoryJournal::class);
        $app->singleton(PriceCandidates::class);
        $app->singleton(PriceWrites::class);
        $app->singleton(PriceLists::class, DatabasePriceLists::class);
        $app->singleton(Markets::class, DatabaseMarkets::class);
        // Replaces the core's defaults whichever provider registered first (the core binds them with `singletonIf`).
        $app->singleton(PriceResolver::class, DatabasePriceResolver::class);
        $app->singleton(TaxEngine::class, PricingTaxEngine::class);
        $app->singleton(Prices::class, DatabasePrices::class);
        $app->singleton(StagedPrices::class, DatabaseStagedPrices::class);
        $app->singleton(TaxZoneLookup::class);
        $app->singleton(TaxRateLookup::class);
        $app->singleton(Taxes::class, DatabaseTaxes::class);
        $app->singleton(Pricing::class);
    }
}
