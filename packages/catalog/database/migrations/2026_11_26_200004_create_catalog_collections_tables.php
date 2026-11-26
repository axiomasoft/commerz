<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $products = $this->table('products');

        $collections = $this->create('collections', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->string('code', 64);
            $table->jsonb('name');
            $table->jsonb('description')->nullable();
            $table->string('kind', 16);
            $table->jsonb('rules')->nullable();
            $table->smallInteger('rules_version')->default(1);
            $table->string('status', 16)->default('active');
            $this->timestampsTz($table);
            $this->unique($table, 'code');
            $this->index($table, ['kind', 'status']);
        });

        if ($this->db()->getDriverName() === 'pgsql') {
            $this->check($collections, 'kind_valid', "kind in ('manual', 'smart')");
            $this->check($collections, 'status_valid', "status in ('active', 'archived')");
            $this->check($collections, 'name_object', "jsonb_typeof(name) = 'object'");
            $this->check($collections, 'description_object', "description is null or jsonb_typeof(description) = 'object'");
            $this->check(
                $collections,
                'rules_valid',
                "(kind = 'manual' and rules is null) or (kind = 'smart' and rules is not null and jsonb_typeof(rules) = 'array' and jsonb_array_length(rules) > 0)",
            );
            $this->check($collections, 'rules_version_positive', 'rules_version > 0');
        }

        $items = $this->create('collection_products', function (Blueprint $table) use ($collections, $products): void {
            $table->uuid('collection_id');
            $table->uuid('product_id');
            $table->bigInteger('position')->default(0);
            $table->primary(['collection_id', 'product_id']);
            $this->index($table, ['collection_id', 'position']);
            $this->index($table, 'product_id');
            $this->foreign($table, 'collection_id')->references('id')->on($collections)->restrictOnDelete();
            $this->foreign($table, 'product_id')->references('id')->on($products)->restrictOnDelete();
        });

        if ($this->db()->getDriverName() === 'pgsql') {
            $this->check($items, 'position_not_negative', 'position >= 0');
        }
    }

    public function down(): void
    {
        $this->drop('collection_products');
        $this->drop('collections');
    }
};
