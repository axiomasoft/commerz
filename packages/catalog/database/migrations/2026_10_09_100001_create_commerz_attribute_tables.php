<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $attributes = $this->create('attributes', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->string('code', 64);
            $table->string('type', 64);
            $table->jsonb('name');
            $table->jsonb('config')->default(new Expression("'{}'::jsonb"));
            $table->integer('config_version')->default(1);
            $table->boolean('filterable')->default(false);
            $table->string('status', 16)->default('active');
            $this->timestampsTz($table);
            $this->unique($table, 'code');
        });

        $this->check($attributes, 'status_valid', "status in ('active', 'deprecated', 'retired')");
        $this->check($attributes, 'config_object', "jsonb_typeof(config) = 'object'");

        $this->create('attribute_choices', function (Blueprint $table) use ($attributes): void {
            $this->uuidPrimary($table);
            $table->uuid('attribute_id');
            $table->string('code', 64);
            $table->jsonb('label');
            $table->integer('position')->default(0);
            $this->timestampsTz($table);
            $this->unique($table, ['attribute_id', 'code']);
            $this->unique($table, ['id', 'attribute_id']);
            $this->foreign($table, 'attribute_id')->references('id')->on($attributes)->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $this->drop('attribute_choices');
        $this->drop('attributes');
    }
};
