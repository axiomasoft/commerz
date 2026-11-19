<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $products = $this->table('products');

        $categories = $this->create('categories', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->uuid('parent_id')->nullable();
            $table->string('code', 64);
            $table->jsonb('name');
            $table->integer('position')->default(0);
            $table->string('status', 16)->default('active');
            $this->timestampsTz($table);
            $this->unique($table, 'code');
            $this->index($table, ['parent_id', 'position']);
        });

        // A self-reference needs the primary key, which Blueprint adds after the table's own foreign keys.
        $this->schema()->table($categories, function (Blueprint $table) use ($categories): void {
            $this->foreign($table, 'parent_id')->references('id')->on($categories)->restrictOnDelete();
        });

        $this->check($categories, 'status_valid', "status in ('active', 'archived')");
        $this->check($categories, 'not_own_parent', 'parent_id is null or parent_id <> id');

        $this->create('category_products', function (Blueprint $table) use ($categories, $products): void {
            $table->uuid('category_id');
            $table->uuid('product_id');
            $table->integer('position')->default(0);
            $table->primary(['category_id', 'product_id']);
            $this->index($table, 'product_id');
            $this->foreign($table, 'category_id')->references('id')->on($categories)->restrictOnDelete();
            $this->foreign($table, 'product_id')->references('id')->on($products)->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $this->drop('category_products');
        $this->drop('categories');
    }
};
