<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Commerz\Schema\TableNames;
use Illuminate\Database\Schema\Blueprint;
use Packman\Schema\Data\Compiler\SchemaFingerprint;

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
            'value' => json_encode(SchemaFingerprint::document($this->tables()->prefix(), TableNames::OWNER), JSON_THROW_ON_ERROR),
        ]);
    }

    public function down(): void
    {
        $this->drop('schema_state');
    }
};
