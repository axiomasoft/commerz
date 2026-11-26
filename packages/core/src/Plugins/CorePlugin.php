<?php

declare(strict_types=1);

namespace Commerz\Plugins;

use Commerz\CommerzServiceProvider;
use Commerz\Configuration\CommerzOptions;
use Commerz\Diagnostics\CommerzDoctorCheck;
use Commerz\Errors\CommerzProblemDefinitions;
use Commerz\Outbox\Contracts\OutboxConsumer;
use Commerz\Panels\ChannelPanel;
use Commerz\Panels\StorePanel;
use Commerz\Ports\Sellables\Contracts\SellableProvider;
use Commerz\Quotes\Contracts\CartTransformRule;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Internal\BaseStage;
use Commerz\Quotes\Internal\CartTransformStage;
use Commerz\Quotes\Internal\TotalsStage;
use Commerz\Schema\CommerzSchemaSets;
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
 * The Commerz core code plugin. Downstream plugins require it explicitly to use its
 * contribution kinds, schema, configuration, panels, and diagnostics.
 */
#[Api(since: '0.1')]
final class CorePlugin implements ExtensionPlugin, WithConfiguration, WithDiagnostics, WithPanels, WithSchema
{
    use Configurable;
    use OwnsSchema;
    use ProvidesDiagnostics;
    use ProvidesPanels;

    public const string ID = 'commerz.core';

    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: self::ID,
            version: '0.1.0',
            resourceRoot: dirname(__DIR__, 2),
            databases: ['pgsql'],
        );
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->declareKind(new ContributionKind(OutboxConsumer::KIND, OutboxConsumer::class, self::ID));
        $registrar->declareKind(new ContributionKind(SellableProvider::KIND, SellableProvider::class, self::ID));
        $registrar->declareKind(new ContributionKind(QuoteStage::KIND, QuoteStage::class, self::ID));
        $registrar->declareKind(new ContributionKind(CartTransformRule::KIND, CartTransformRule::class, self::ID));

        $registrar->contribute(QuoteStage::KIND, CartTransformStage::class);
        $registrar->contribute(QuoteStage::KIND, BaseStage::class);
        $registrar->contribute(QuoteStage::KIND, TotalsStage::class);
    }

    #[Override]
    public function configRoot(): string
    {
        return 'commerz';
    }

    #[Override]
    public function defaultConfigPath(): string
    {
        return dirname(__DIR__, 2).'/config/commerz.php';
    }

    /** @return class-string<CommerzOptions> */
    #[Override]
    public function optionsClass(): string
    {
        return CommerzOptions::class;
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
        return CommerzServiceProvider::NOUNS;
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
        return [CommerzSchemaSets::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function healthChecks(): array
    {
        return [CommerzDoctorCheck::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function problemProviders(): array
    {
        return [CommerzProblemDefinitions::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function panelProviders(): array
    {
        return [StorePanel::class, ChannelPanel::class];
    }
}
