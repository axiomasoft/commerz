<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Packman\Schema\Contracts\Migration\SchemaMigrator;
use Packman\Schema\Enums\SchemaScope;
use Packman\Schema\Exceptions\SchemaSetException;

trait UsesSqliteTestDatabase
{
    private string $sqlitePath = '';

    /** @param  Application  $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->sqlitePath = sys_get_temp_dir().'/commerz_sqlite_guard_'.bin2hex(random_bytes(6)).'_test';
        touch($this->sqlitePath);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => $this->sqlitePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }
}

uses(UsesSqliteTestDatabase::class);

afterEach(function (): void {
    DB::disconnect('testing');

    if ($this->sqlitePath !== '' && is_file($this->sqlitePath)) {
        @unlink($this->sqlitePath);
    }
});

/** @return list<string> */
function sqliteCommerceTables(): array
{
    $rows = DB::connection('testing')->select(
        "select name from sqlite_master where type = 'table' and name like 'cmz_%' order by name",
    );

    return array_map(static fn (object $row): string => (string) $row->name, $rows);
}

it('rejects SQLite migrations via SchemaMigrator with zero cmz_* tables created', function (): void {
    expect(fn () => app(SchemaMigrator::class)->migrate(SchemaScope::Central))
        ->toThrow(SchemaSetException::class, 'sqlite');

    expect(sqliteCommerceTables())->toBe([]);
});

it('rejects raw Laravel artisan migrate and commerz:install on SQLite before creating any cmz_* tables', function (): void {
    expect(fn () => $this->artisan('migrate'))
        ->toThrow(SchemaSetException::class, 'sqlite');

    expect(sqliteCommerceTables())->toBe([]);

    expect(fn () => $this->artisan('commerz:install'))
        ->toThrow(SchemaSetException::class, 'sqlite');

    expect(sqliteCommerceTables())->toBe([]);
});
