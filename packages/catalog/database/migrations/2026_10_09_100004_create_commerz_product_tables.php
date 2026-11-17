<?php

declare(strict_types=1);

use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        $types = $this->table('product_types');
        $options = $this->table('options');

        $products = $this->create('products', function (Blueprint $table) use ($types): void {
            $this->uuidPrimary($table);
            $table->uuid('product_type_id');
            $table->string('handle', 191);
            $table->jsonb('name');
            $table->jsonb('description')->nullable();
            $table->string('status', 16)->default('active');
            $table->jsonb('attributes')->default(new Expression("'{}'::jsonb"));
            $this->timestampsTz($table);
            $this->unique($table, 'handle');
            $this->index($table, 'product_type_id');
            $this->index($table, 'status');
            $this->foreign($table, 'product_type_id')->references('id')->on($types)->restrictOnDelete();
        });

        $this->check($products, 'status_valid', "status in ('draft', 'active', 'archived')");
        $this->check($products, 'attributes_object', "jsonb_typeof(attributes) = 'object'");

        $this->create('product_options', function (Blueprint $table) use ($products, $options): void {
            $table->uuid('product_id');
            $table->uuid('option_id');
            $table->integer('position')->default(0);
            $table->primary(['product_id', 'option_id']);
            $this->foreign($table, 'product_id')->references('id')->on($products)->cascadeOnDelete();
            $this->foreign($table, 'option_id')->references('id')->on($options)->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $this->drop('product_options');
        $this->drop('products');
    }
};
