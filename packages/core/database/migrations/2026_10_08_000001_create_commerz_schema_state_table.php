<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Commerz\Schema\Internal\SchemaFingerprint;
use Illuminate\Database\Schema\Blueprint;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $table = $this->create('schema_state', function (Blueprint $table): void {
            $table->string('key', 64)->primary();
            $table->jsonb('value');
            $table->timestampTz('updated_at', 6)->useCurrent();
        });

        $this->db()->table($table)->insert([
            'key' => SchemaFingerprint::KEY,
            'value' => json_encode(SchemaFingerprint::document($this->tables()->prefix()), JSON_THROW_ON_ERROR),
        ]);
    }

    public function down(): void
    {
        $this->drop('schema_state');
    }
};
