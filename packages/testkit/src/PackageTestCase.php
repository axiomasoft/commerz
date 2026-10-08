<?php

declare(strict_types=1);

namespace Commerz\Testkit;

use Commerz\Attributes\Api;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

#[Api(since: '0.1')]
abstract class PackageTestCase extends Orchestra
{
    /** @return list<class-string> */
    abstract protected function commerzProviders(): array;

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return $this->commerzProviders();
    }

    /** @param  Application  $app */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->databaseConnection());
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
