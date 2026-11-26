<?php

declare(strict_types=1);

namespace Commerz\Schema;

use Commerz\Configuration\CommerzOptions;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\Migrations\Migrator;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Schema\Contracts\Migration\SchemaSetProvider;
use Packman\Schema\Contracts\Migration\SchemaSets;
use Packman\Schema\Data\Migration\SchemaSet;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Enums\SchemaScope;
use Packman\Schema\Exceptions\SchemaSetException;
use ReflectionObject;

/**
 * Declares the central PostgreSQL schema set owned by `axiomasoft/commerz` and governs Laravel migration execution
 * across all registered Commerz `SchemaSet` declarations.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final readonly class CommerzSchemaSets implements SchemaSetProvider
{
    public function __construct(
        private ?Repository $config = null,
    ) {}

    /** @return list<SchemaSet> */
    #[Override]
    public function sets(): array
    {
        return [
            self::forPackage(
                config: $this->config,
                package: 'axiomasoft/commerz',
                name: 'commerz',
                path: dirname(__DIR__, 2).'/database/migrations',
                version: 2,
            ),
        ];
    }

    public static function forPackage(
        CommerzOptions|Repository|null $config,
        string $package,
        string $name,
        string $path,
        int $version = 1,
    ): SchemaSet {
        $options = match (true) {
            $config instanceof CommerzOptions => $config,
            $config instanceof Repository => self::optionsFromRepository($config),
            default => null,
        };

        $scope = $options instanceof CommerzOptions ? $options->schemaScope : SchemaScope::Central;
        $connection = $options instanceof CommerzOptions && $scope === SchemaScope::Dedicated ? $options->connection : null;

        return new SchemaSet(
            package: $package,
            name: $name,
            scope: $scope,
            path: $path,
            version: $version,
            drivers: ['pgsql'],
            lifecycle: SchemaLifecycle::Platform,
            connection: $connection,
        );
    }

    private static function optionsFromRepository(Repository $config): CommerzOptions
    {
        $raw = $config->get('commerz', []);
        $section = is_array($raw) ? array_intersect_key($raw, array_flip(['connection', 'schema_scope'])) : [];

        return CommerzOptions::fromArray($section, 'commerz');
    }

    /**
     * Registers Commerz `SchemaSet` paths with Laravel's `migrator` and guards execution so unsupported database
     * drivers fail cleanly with {@see SchemaSetException} before creating partial commerce tables.
     */
    public static function governMigrations(Application $app): void
    {
        if ($app->bound('commerz.schema_migrations_governed')) {
            return;
        }

        $app->instance('commerz.schema_migrations_governed', true);

        $app->afterResolving('migrator', static function (mixed $migrator, Application $app): void {
            if ($migrator instanceof Migrator) {
                self::registerPaths($migrator, $app);
            }
        });

        $app->booted(static function (Application $app): void {
            if ($app->resolved('migrator')) {
                self::registerPaths($app->make('migrator'), $app);
            }

            if ($app->bound(Dispatcher::class)) {
                $app->make(Dispatcher::class)->listen(
                    MigrationStarted::class,
                    static function (MigrationStarted $event) use ($app): void {
                        self::assertMigrationSupported($event, $app);
                    },
                );
            }
        });
    }

    /**
     * Validates that the configured Commerz database connection satisfies every registered Commerz `SchemaSet`.
     */
    public static function assertConnectionSupported(Application $app): void
    {
        $configuredConnection = $app->bound(CommerzOptions::class)
            ? $app->make(CommerzOptions::class)->connection
            : null;
        $connections = $app->make(ConnectionResolverInterface::class);

        foreach (self::commerzSets($app) as $set) {
            $driver = $connections->connection($set->connection ?? $configuredConnection)->getDriverName();

            if (! in_array($driver, $set->drivers, true)) {
                throw SchemaSetException::unsupportedDriver(id: $set->id(), driver: $driver);
            }
        }
    }

    /** @return list<string> Paths of the Commerz schema sets selected by this host. */
    public static function migrationPaths(Application $app): array
    {
        return array_map(static fn (SchemaSet $set): string => $set->path, self::commerzSets($app));
    }

    private static function registerPaths(Migrator $migrator, Application $app): void
    {
        if ($app->make(Repository::class)->get('packman.schema.auto_load_migrations', true) !== true) {
            return;
        }

        foreach (self::migrationPaths($app) as $path) {
            $migrator->path($path);
        }
    }

    private static function assertMigrationSupported(MigrationStarted $event, Application $app): void
    {
        $file = new ReflectionObject($event->migration)->getFileName();

        if (! is_string($file)) {
            return;
        }

        $migrationDir = realpath(dirname($file));

        if ($migrationDir === false) {
            return;
        }

        foreach (self::commerzSets($app) as $set) {
            $setDir = realpath($set->path);

            if ($setDir === false || $setDir !== $migrationDir) {
                continue;
            }

            $migratorConnection = $app->resolved('migrator')
                ? $app->make('migrator')->getConnection()
                : null;

            $connectionName = $event->migration->getConnection() ?? $set->connection ?? $migratorConnection;
            $driver = $app->make(ConnectionResolverInterface::class)->connection($connectionName)->getDriverName();

            if (! in_array($driver, $set->drivers, true)) {
                throw SchemaSetException::unsupportedDriver(id: $set->id(), driver: $driver);
            }

            return;
        }
    }

    /** @return list<SchemaSet> */
    private static function commerzSets(Application $app): array
    {
        if (! $app->bound(SchemaSets::class)) {
            return [];
        }

        return array_values(array_filter(
            $app->make(SchemaSets::class)->all(),
            static fn (SchemaSet $set): bool => str_starts_with($set->package, 'axiomasoft/commerz'),
        ));
    }
}
