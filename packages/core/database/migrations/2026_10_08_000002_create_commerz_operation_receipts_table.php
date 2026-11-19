<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $this->create('operation_receipts', function (Blueprint $table): void {
            $table->string('scope', 64);
            $table->string('key', 191);
            $table->char('fingerprint', 64);
            $table->jsonb('result')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->primary(['scope', 'key']);
            $this->index($table, 'created_at');
        });
    }

    public function down(): void
    {
        $this->drop('operation_receipts');
    }
};
