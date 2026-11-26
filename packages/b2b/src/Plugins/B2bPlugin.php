<?php

declare(strict_types=1);

namespace Commerz\B2b\Plugins;

use Commerz\B2b\B2bServiceProvider;
use Commerz\B2b\Configuration\B2bOptions;
use Commerz\B2b\Diagnostics\B2bDoctorCheck;
use Commerz\B2b\Errors\B2bProblemDefinitions;
use Commerz\B2b\Panels\B2bPanel;
use Commerz\B2b\Quotes\Internal\LockedQuotePriceStage;
use Commerz\B2b\Schema\B2bSchemaSets;
use Commerz\Plugins\CorePlugin;
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
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

/**
 * The B2B code plugin: contributes the locked-price quote stage and exposes configuration, schema ownership,
 * diagnostics, problem definitions, and panel.
 */
#[Api(since: '0.1')]
final class B2bPlugin implements ExtensionPlugin, WithConfiguration, WithDiagnostics, WithPanels, WithSchema
{
    use Configurable;
    use OwnsSchema;
    use ProvidesDiagnostics;
    use ProvidesPanels;

    public const string ID = 'commerz.b2b';

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
        $registrar->contribute(QuoteStage::KIND, LockedQuotePriceStage::class);
    }

    #[Override]
    public function configRoot(): string
    {
        return 'commerz-b2b';
    }

    #[Override]
    public function defaultConfigPath(): string
    {
        return dirname(__DIR__, 2).'/config/commerz-b2b.php';
    }

    /** @return class-string<B2bOptions> */
    #[Override]
    public function optionsClass(): string
    {
        return B2bOptions::class;
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
        return B2bServiceProvider::NOUNS;
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
        return [B2bSchemaSets::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function healthChecks(): array
    {
        return [B2bDoctorCheck::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function problemProviders(): array
    {
        return [B2bProblemDefinitions::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function panelProviders(): array
    {
        return [B2bPanel::class];
    }
}
