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
use Commerz\Plugins\CommerzPlugins;
use Commerz\Plugins\CorePlugin;
use Commerz\Schema\CommerzSchemaSets;
use Commerz\Schema\Internal\LegacyFingerprint;
use Commerz\Schema\TableNames;
use Illuminate\Console\Application as Artisan;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Foundation\Application as FoundationApplication;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Config\Laravel\ConfigSectionRegistration;
use Packman\Extensions\Laravel\ExtensionFacetRegistrar;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\PackmanServiceProvider;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Laravel\PluginsServiceProvider;
use Packman\Schema\Contracts\Compiler\SchemaOwnership;
use Packman\Schema\Data\Compiler\TableNames as PackmanTables;
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
        CommerzPlugins::builtInFor($app, CorePlugin::class);

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

        CommerzSchemaSets::governMigrations($app);

        $app->singleton(CommerzDoctorCheck::class, static fn (Application $app): CommerzDoctorCheck => new CommerzDoctorCheck(
            config: $app->make(ConfigRepository::class),
            connections: $app->make(ConnectionResolverInterface::class),
            optionsResolver: static fn (): CommerzOptions => $app->make(CommerzOptions::class),
            tablesResolver: static fn (): TableNames => $app->make(TableNames::class),
            pluginsResolver: static fn (): PluginInspector => $app->make(PluginInspector::class),
        ));

        self::bindTableNames($app);
        self::bridgePluginConfiguration($app);

        $app->singleton(Transactions::class, DatabaseTransactions::class);
        $app->singleton(CommittedEvents::class, ConnectionCommittedEvents::class);
        $app->singleton(OperationReceipts::class, DatabaseOperationReceipts::class);
        $app->singleton(Outbox::class, DatabaseOutbox::class);
        $app->singleton(OutboxRelay::class);
    }

    #[Override]
    public function boot(Application $app): void
    {
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
        $app->singletonIf(TableNames::class, static fn (Application $app): TableNames => new TableNames(static function () use ($app): PackmanTables {
            $options = $app->make(CommerzOptions::class);

            return new PackmanTables(
                prefix: $options->tablePrefix,
                ownership: SchemaExtensions::ownershipFor($app->make(SchemaOwnership::class), $app->make(CorePlugin::class)->tablePrefix()),
                connections: $app->make('db'),
                connection: $options->connection,
                configPath: 'commerz.table_prefix',
                owner: TableNames::OWNER,
                ownsLegacy: LegacyFingerprint::owns(...),
            );
        }));
    }

    private static function bridgePluginConfiguration(Application $app): void
    {
        $app->beforeResolving(Contributions::class, static function () use ($app): void {
            $options = $app->make(CommerzOptions::class);
            $config = $app->make(ConfigRepository::class);

            foreach (['plugins' => $options->plugins, 'disabled_plugins' => $options->disabledPlugins] as $key => $values) {
                $configured = $config->get('packman.'.$key, []);

                if (! is_array($configured)) {
                    throw new InvalidConfiguration(['packman.'.$key.': expected an array']);
                }

                $additional = array_filter($values, static fn (string $value): bool => ! in_array($value, $configured, true));
                $config->set('packman.'.$key, array_values([...$configured, ...$additional]));
            }
        });
    }
}
