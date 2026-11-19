<?php

declare(strict_types=1);

namespace Commerz\EndToEnd\Support;

use Commerz\Schema\TableNames;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Committed data for the scenarios that a test transaction would break: payments, refunds, carrier booking and the
 * outbox relay refuse to run inside a transaction, and forked workers see only committed rows. The schema is migrated
 * on the isolated test database and every table of the family is truncated before and after the test.
 */
final class CommittedDatabase
{
    public static function prepare(): void
    {
        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            throw new RuntimeException('The migrations of the family failed: '.Artisan::output());
        }

        self::truncate();
    }

    /**
     * Empties every `cmz_` table of the current schema. The migrations stay, and so does the schema state: it records
     * the installed prefix that guards every later connection (`SchemaFingerprint`), and is install data, not test data.
     */
    public static function truncate(): void
    {
        $tables = DB::table('information_schema.tables')
            ->where('table_schema', DB::raw('current_schema()'))
            ->where('table_type', 'BASE TABLE')
            ->where('table_name', 'like', 'cmz\_%')
            ->where('table_name', '!=', app(TableNames::class)->table('schema_state'))
            ->orderBy('table_name')
            ->pluck('table_name')
            ->all();

        if ($tables !== []) {
            DB::statement('truncate '.implode(', ', array_map(static fn (mixed $table): string => '"'.(string) $table.'"', $tables)).' restart identity cascade');
        }
    }
}
