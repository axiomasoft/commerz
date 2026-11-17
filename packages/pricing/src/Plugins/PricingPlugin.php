<?php

declare(strict_types=1);

namespace Commerz\Pricing\Plugins;

use Commerz\Plugins\CorePlugin;
use Commerz\Pricing\Calculation\Contracts\TaxCalculator;
use Commerz\Pricing\Calculation\Internal\RatesTaxCalculator;
use Commerz\Pricing\Calculation\Internal\TaxStage;
use Commerz\Pricing\Configuration\PricingOptions;
use Commerz\Pricing\Diagnostics\PricingDoctorCheck;
use Commerz\Pricing\Errors\PricingProblemDefinitions;
use Commerz\Pricing\Panels\PriceListsPanel;
use Commerz\Pricing\Panels\TaxRatesPanel;
use Commerz\Pricing\Panels\TaxZonesPanel;
use Commerz\Pricing\PricingServiceProvider;
use Commerz\Pricing\Schema\PricingSchemaSets;
use Commerz\Quotes\Contracts\QuoteStage;
use Override;
use Packman\Attributes\Api;
use Packman\Extensions\Concerns\Composition\ProvidesPanels;
use Packman\Extensions\Concerns\Configuration\Configurable;
use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Concerns\Runtime\ProvidesDiagnostics;
use Packman\Extensions\Contracts\Composition\WithPanels;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Extensions\Contracts\Runtime\WithDiagnostics;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Contributions\ContributionKind;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

/**
 * The pricing's code plugin: declares `tax_calculator`, contributes `rates` and the `tax` quote stage,
 * and exposes pricing configuration, schema, diagnostics, and panels.
 */
#[Api(since: '0.1')]
final class PricingPlugin implements ExtensionPlugin, WithConfiguration, WithDiagnostics, WithPanels, WithSchema
{
    use Configurable;
    use OwnsSchema;
    use ProvidesDiagnostics;
    use ProvidesPanels;

    public const string ID = 'commerz.pricing';

    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: self::ID,
            version: '0.1.0',
            requires: [CorePlugin::ID],
            resourceRoot: dirname(__DIR__, 2),
            databases: ['pgsql'],
        );
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->declareKind(new ContributionKind(TaxCalculator::KIND, TaxCalculator::class, self::ID));
        $registrar->contribute(TaxCalculator::KIND, RatesTaxCalculator::class);
        $registrar->contribute(QuoteStage::KIND, TaxStage::class);
    }

    #[Override]
    public function configRoot(): string
    {
        return 'commerz-pricing';
    }

    #[Override]
    public function defaultConfigPath(): string
    {
        return dirname(__DIR__, 2).'/config/commerz-pricing.php';
    }

    /** @return class-string<PricingOptions> */
    #[Override]
    public function optionsClass(): string
    {
        return PricingOptions::class;
    }

    #[Override]
    public function tablePrefix(): string
    {
        return 'cmz_';
    }

    /** @return list<string> */
    #[Override]
    public function tableNouns(): array
    {
        return PricingServiceProvider::NOUNS;
    }

    #[Override]
    public function migrationPath(): string
    {
        return dirname(__DIR__, 2).'/database/migrations';
    }

    /** @return list<class-string> */
    #[Override]
    public function schemaSetProviders(): array
    {
        return [PricingSchemaSets::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function healthChecks(): array
    {
        return [PricingDoctorCheck::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function problemProviders(): array
    {
        return [PricingProblemDefinitions::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function panelProviders(): array
    {
        return [PriceListsPanel::class, TaxZonesPanel::class, TaxRatesPanel::class];
    }
}
