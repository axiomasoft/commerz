<?php

declare(strict_types=1);

namespace Commerz\Laravel\Registrars;

use Commerz\Configuration\CommerzOptions;
use Commerz\Diagnostics\CommerzDoctorCheck;
use Commerz\Internal\Console\InstallCommand;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\OperationReceipts;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Operations\Internal\ConnectionCommittedEvents;
use Commerz\Operations\Internal\Console\PruneOperationsCommand;
use Commerz\Operations\Internal\DatabaseOperationReceipts;
use Commerz\Operations\Internal\DatabaseTransactions;
use Commerz\Outbox\Contracts\Outbox;
use Commerz\Outbox\Internal\Console\RelayOutboxCommand;
use Commerz\Outbox\Internal\DatabaseOutbox;
use Commerz\Outbox\Internal\OutboxRelay;
use Commerz\Plugins\CorePlugin;
use Commerz\Plugins\Internal\BuiltInPluginCatalog;
use Illuminate\Console\Application as Artisan;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Foundation\Application as FoundationApplication;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Config\Laravel\ConfigSectionRegistration;
use Packman\Extensions\Laravel\ExtensionFacetRegistrar;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\PackmanServiceProvider;
use Packman\Panels\Internal\Configuration\PanelsOptions;
use Packman\Panels\Laravel\PanelExtensionBridge;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Laravel\PluginsServiceProvider;
use Packman\Plugins\Testing\PluginGraph;
use Packman\Schema\Contracts\Compiler\SchemaOwnership;
use Packman\Schema\Data\Compiler\TableNames;
use Packman\Schema\Laravel\SchemaExtensions;
use Packman\Schema\Laravel\SchemaServiceProvider;

/**
 * Wires Commerz's core extension plugin, options, schema ownership, diagnostics, panels, outbox, and operations.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final class CommerzCoreRegistrar implements PackageRegistrar
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

        $app->singletonIf(CorePlugin::class);
        $app->singletonIf(BuiltInPluginCatalog::class, static fn (): BuiltInPluginCatalog => new BuiltInPluginCatalog);

        if (! $app->bound('commerz.core_facets_registered')) {
            $app->instance('commerz.core_facets_registered', true);

            ExtensionFacetRegistrar::register(
                $app,
                CorePlugin::class,
                dirname(__DIR__, 3).'/composer.json',
            );

            ConfigSectionRegistration::own($app, 'commerz', static function (array $section): void {
                CommerzOptions::fromArray($section, 'commerz');
            });

            SchemaExtensions::register($app, CorePlugin::class, CorePlugin::ID);
        }

        $app->singleton(CommerzDoctorCheck::class, static fn (Application $app): CommerzDoctorCheck => new CommerzDoctorCheck(
            config: $app->make(ConfigRepository::class),
            connections: $app->make(ConnectionResolverInterface::class),
            optionsResolver: static fn (): CommerzOptions => $app->make(CommerzOptions::class),
            tablesResolver: static fn (): TableNames => $app->make(TableNames::class),
            pluginsResolver: static fn (): PluginInspector => $app->make(PluginInspector::class),
        ));

        self::bindTableNames($app);
        self::bindPluginRegistry($app);

        if ($app->bound(PanelsOptions::class)) {
            PanelExtensionBridge::registerExtension($app, $app->make(CorePlugin::class));
        } else {
            $app->afterResolving(PanelsOptions::class, static function (mixed $_, Application $app): void {
                PanelExtensionBridge::registerExtension($app, $app->make(CorePlugin::class));
            });
        }

        $app->singleton(Transactions::class, DatabaseTransactions::class);
        $app->singleton(CommittedEvents::class, ConnectionCommittedEvents::class);
        $app->singleton(OperationReceipts::class, DatabaseOperationReceipts::class);
        $app->singleton(Outbox::class, DatabaseOutbox::class);
        $app->singleton(OutboxRelay::class);
    }

    #[Override]
    public function boot(Application $app): void
    {
        if (! $app->resolved(TableNames::class)) {
            self::bindTableNames($app);
        }

        if (! $app->resolved(Contributions::class) && ! $app->resolved(PluginInspector::class)) {
            self::bindPluginRegistry($app);
        }

        if ($app->runningInConsole()) {
            Artisan::starting(static function (Artisan $artisan): void {
                $artisan->resolveCommands([
                    InstallCommand::class,
                    RelayOutboxCommand::class,
                    PruneOperationsCommand::class,
                ]);
            });
        }
    }

    private static function bindTableNames(Application $app): void
    {
        $app->singleton(TableNames::class, static function (Application $app): TableNames {
            $options = $app->make(CommerzOptions::class);

            return new TableNames(
                prefix: $options->tablePrefix,
                ownership: $app->make(SchemaOwnership::class),
                connections: $app->make('db'),
                connection: $options->connection,
                configPath: 'commerz.table_prefix',
            );
        });
    }

    private static function bindPluginRegistry(Application $app): void
    {
        $app->singleton(Contributions::class, static function (Application $app): Contributions&PluginInspector {
            $options = $app->make(CommerzOptions::class);
            $configRepo = $app->bound(ConfigRepository::class) ? $app->make(ConfigRepository::class) : null;
            $packmanPlugins = $configRepo !== null ? $configRepo->get('packman.plugins', []) : [];
            $packmanDisabled = $configRepo !== null ? $configRepo->get('packman.disabled_plugins', []) : [];

            $configured = $options->plugins;
            foreach (is_array($packmanPlugins) ? $packmanPlugins : [] as $class) {
                if (is_string($class) && $class !== '' && ! in_array($class, $configured, true)) {
                    $configured[] = $class;
                }
            }

            $disabled = $options->disabledPlugins;
            foreach (is_array($packmanDisabled) ? $packmanDisabled : [] as $id) {
                if (is_string($id) && $id !== '' && ! in_array($id, $disabled, true)) {
                    $disabled[] = $id;
                }
            }

            $catalog = $app->make(BuiltInPluginCatalog::class);
            $catalog->seal();
            $builtIn = array_values(array_unique([CorePlugin::class, ...$catalog->classes()]));

            /** @var list<class-string> $configuredList */
            $configuredList = $configured;

            return PluginGraph::compile(
                container: $app,
                plugins: $configuredList,
                corePluginId: CorePlugin::ID,
                builtIn: $builtIn,
                disabled: $disabled,
            );
        });

        $app->alias(Contributions::class, PluginInspector::class);
    }
}
