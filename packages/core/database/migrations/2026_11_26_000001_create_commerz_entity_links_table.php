<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $table = $this->create('entity_links', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->string('link_code', 128);
            $table->string('left_type', 64);
            $table->string('left_id', 120);
            $table->string('right_type', 64);
            $table->string('right_id', 120);
            $table->integer('position')->default(0);
            $table->jsonb('extra')->default(new Expression("'{}'::jsonb"));
            $this->timestampsTz($table);

            $this->unique($table, ['link_code', 'left_type', 'left_id', 'right_type', 'right_id']);
            $this->index($table, ['link_code', 'left_type', 'left_id', 'position']);
            $this->index($table, ['link_code', 'right_type', 'right_id', 'position']);
        });

        $this->check($table, 'position_not_negative', 'position >= 0');
    }

    public function down(): void
    {
        $this->drop('entity_links');
    }
};
