<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        $attributes = $this->table('attributes');
        $options = $this->table('options');

        $types = $this->create('product_types', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->string('code', 64);
            $table->jsonb('name');
            $table->string('kind', 64);
            $table->string('status', 16)->default('active');
            $this->timestampsTz($table);
            $this->unique($table, 'code');
        });

        $this->check($types, 'status_valid', "status in ('active', 'archived')");

        $typeAttributes = $this->create('product_type_attributes', function (Blueprint $table) use ($types, $attributes): void {
            $table->uuid('product_type_id');
            $table->uuid('attribute_id');
            $table->string('level', 16);
            $table->boolean('required')->default(false);
            $table->integer('position')->default(0);
            $table->primary(['product_type_id', 'attribute_id']);
            $this->index($table, 'attribute_id');
            $this->foreign($table, 'product_type_id')->references('id')->on($types)->restrictOnDelete();
            $this->foreign($table, 'attribute_id')->references('id')->on($attributes)->restrictOnDelete();
        });

        $this->check($typeAttributes, 'level_valid', "level in ('product', 'variant')");

        $this->create('product_type_options', function (Blueprint $table) use ($types, $options): void {
            $table->uuid('product_type_id');
            $table->uuid('option_id');
            $table->integer('position')->default(0);
            $table->primary(['product_type_id', 'option_id']);
            $this->foreign($table, 'product_type_id')->references('id')->on($types)->restrictOnDelete();
            $this->foreign($table, 'option_id')->references('id')->on($options)->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $this->drop('product_type_options');
        $this->drop('product_type_attributes');
        $this->drop('product_types');
    }
};
