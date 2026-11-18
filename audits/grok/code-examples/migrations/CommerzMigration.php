<?php

declare(strict_types=1);

namespace Commerz\Schema\Internal;

use Commerz\Config\Data\CommerzConfig;
use Commerz\Schema\Contracts\TableNames;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * База миграций commerz: своё соединение (commerz.connection) и имена через TableNames (префикс из cmz_schema_state).
 * Имена ограничений — legacy-platform: {table}_{cols}_{uq|ix|fk|ck} и {table}_pk; > 63 байт — хэш-суффикс (как SchemaNames Chatom).
 * Регистрация — publishesMigrations/PluginRegistrar::migrations, не loadMigrationsFrom (legacy-platform dev-doctrine :99).
 */
abstract class CommerzMigration extends Migration
{
    public function getConnection(): ?string
    {
        return app(CommerzConfig::class)->connection;
    }

    protected function t(string $logical): string
    {
        return app(TableNames::class)->of($logical);
    }

    /** Laravel на PostgreSQL игнорирует имя в primary() — переименовываем, как legacy-platform (tsk_stages_pkey → tsk_stages_pk). */
    protected function namePrimaryKey(string $table): void
    {
        DB::connection($this->getConnection())->statement("ALTER TABLE {$table} RENAME CONSTRAINT {$table}_pkey TO {$table}_pk");
    }

    protected function check(string $table, string $name, string $expression): void
    {
        DB::connection($this->getConnection())->statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$name}_ck CHECK ({$expression})");
    }
}
