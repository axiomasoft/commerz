<?php

declare(strict_types=1);

namespace Commerz\Testkit;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Override;
use Packman\Attributes\Api;
use Packman\Testkit\PackageTestCase as PackmanPackageTestCase;
use RuntimeException;

#[Api(since: '0.1')]
abstract class PackageTestCase extends PackmanPackageTestCase
{
    /** @return list<class-string> */
    abstract protected function commerzProviders(): array;

    private static ?string $migratedFor = null;

    /**
     * `RefreshDatabase` migrates once per process, with the schema of the first test it sees. One process that runs
     * suites of several packages needs a fresh migration whenever the provider set (hence the schema) changes.
     */
    #[Override]
    protected function setUpTraits()
    {
        $app = $this->app;

        if (! $app instanceof Application || ! $app->environment('testing')) {
            throw new RuntimeException('Commerz tests require APP_ENV=testing. Check phpunit.xml before running migrations.');
        }

        $connections = $app->make(DatabaseManager::class);
        $connection = $app->make(Repository::class)->get('commerz.connection');

        if ($connection !== null && ! is_string($connection)) {
            throw new RuntimeException('Commerz tests require commerz.connection to be null or a test connection name.');
        }

        foreach ([null, $connection] as $name) {
            $database = $connections->connection($name)->getDatabaseName();

            if (! str_ends_with($database, '_test')) {
                throw new RuntimeException("Commerz tests require a database ending in _test; resolved '{$database}'. Check phpunit.xml, DB_DATABASE and commerz.connection before running migrations.");
            }
        }

        $providers = implode('|', $this->commerzProviders());

        if (self::$migratedFor !== $providers) {
            RefreshDatabaseState::$migrated = false;
            self::$migratedFor = $providers;
        }

        return parent::setUpTraits();
    }

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    #[Override]
    protected function getPackageProviders($app): array
    {
        return $this->commerzProviders();
    }

    /** @param  Application  $app */
    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make(Repository::class);
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', $this->databaseConnection());
    }

    /** @return array<string, mixed> */
    protected function databaseConnection(): array
    {
        return [
            'driver' => 'pgsql',
            'host' => self::env('DB_HOST', '127.0.0.1'),
            'port' => self::env('DB_PORT', '54329'),
            'database' => self::env('DB_DATABASE', 'commerz_test'),
            'username' => self::env('DB_USERNAME', 'commerz'),
            'password' => self::env('DB_PASSWORD', 'commerz'),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ];
    }

    private static function env(string $key, string $default): string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? $default : $value;
    }
}
