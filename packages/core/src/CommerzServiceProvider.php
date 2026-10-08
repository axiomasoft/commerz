<?php

declare(strict_types=1);

namespace Commerz;

use Commerz\Configuration\CommerzConfig;
use Commerz\Console\DoctorCommand;
use Commerz\Console\InstallCommand;
use Commerz\Operations\Contracts\OperationReceipts;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Operations\Internal\Console\PruneOperationsCommand;
use Commerz\Operations\Internal\DatabaseOperationReceipts;
use Commerz\Operations\Internal\DatabaseTransactions;
use Commerz\Outbox\Contracts\Outbox;
use Commerz\Outbox\Internal\Console\RelayOutboxCommand;
use Commerz\Outbox\Internal\DatabaseOutbox;
use Commerz\Outbox\Internal\OutboxRelay;
use Commerz\Plugins\CommerzPlugins;
use Commerz\Plugins\Contracts\Contributions;
use Commerz\Plugins\Contracts\PluginInspector;
use Commerz\Plugins\Internal\Console\PluginsCommand;
use Commerz\Plugins\Internal\CorePlugin;
use Commerz\Plugins\Internal\PluginCatalog;
use Commerz\Plugins\Internal\PluginGraphCompiler;
use Commerz\Schema\Contracts\SchemaOwnership;
use Commerz\Schema\Internal\SchemaOwnershipRegistry;
use Commerz\Schema\TableNames;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Commerz\Stores\Internal\DatabaseChannels;
use Commerz\Stores\Internal\DatabaseStores;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class CommerzServiceProvider extends ServiceProvider
{
    /** Table nouns owned by the core package. */
    public const array NOUNS = ['store', 'channel', 'schema', 'operation', 'outbox'];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/commerz.php', 'commerz');

        $this->app->singleton(CommerzConfig::class, static function (Application $app): CommerzConfig {
            $config = $app->make(Repository::class)->get('commerz', []);

            return CommerzConfig::fromArray(is_array($config) ? $config : []);
        });

        $this->app->singleton(SchemaOwnership::class, SchemaOwnershipRegistry::class);
        $this->app->singleton(TableNames::class);
        $this->app->singleton(Transactions::class, DatabaseTransactions::class);
        $this->app->singleton(OperationReceipts::class, DatabaseOperationReceipts::class);
        $this->app->singleton(Outbox::class, DatabaseOutbox::class);
        $this->app->singleton(OutboxRelay::class);

        $this->app->singleton(PluginCatalog::class);
        $this->app->singleton(Contributions::class, static fn (Application $app): Contributions => $app->make(PluginGraphCompiler::class)->compile(
            $app->make(PluginCatalog::class)->seal()->entries($app->make(CommerzConfig::class)),
        ));
        $this->app->alias(Contributions::class, PluginInspector::class);

        $this->app->singleton(Stores::class, DatabaseStores::class);
        $this->app->singleton(Channels::class, DatabaseChannels::class);
        $this->app->singleton(Commerz::class);

        CommerzPlugins::builtIn(CorePlugin::class);

        $this->callAfterResolving(SchemaOwnership::class, static function (SchemaOwnership $ownership): void {
            $ownership->declare('commerz.core', self::NOUNS);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/commerz.php' => config_path('commerz.php'),
            ], 'commerz-config');

            $this->commands([
                RelayOutboxCommand::class,
                PruneOperationsCommand::class,
                PluginsCommand::class,
                InstallCommand::class,
                DoctorCommand::class,
            ]);
        }
    }
}
