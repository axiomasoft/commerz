<?php

declare(strict_types=1);

use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        $products = $this->table('products');
        $productOptions = $this->table('product_options');
        $optionValues = $this->table('option_values');

        $variants = $this->create('variants', function (Blueprint $table) use ($products): void {
            $this->uuidPrimary($table);
            $table->uuid('product_id');
            $table->string('sku', 191);
            $table->string('barcode', 64)->nullable();
            $table->string('option_signature', 512)->default('');
            $table->string('status', 16)->default('active');
            $table->integer('position')->default(0);
            $table->jsonb('attributes')->default(new Expression("'{}'::jsonb"));
            $table->string('tax_class', 64)->nullable();
            $table->integer('weight_grams')->nullable();
            $table->integer('length_mm')->nullable();
            $table->integer('width_mm')->nullable();
            $table->integer('height_mm')->nullable();
            $this->timestampsTz($table);
            $this->unique($table, 'sku');
            $this->unique($table, 'barcode');
            $this->unique($table, ['product_id', 'option_signature']);
            $this->unique($table, ['id', 'product_id']);
            $this->index($table, ['product_id', 'position']);
            $this->foreign($table, 'product_id')->references('id')->on($products)->restrictOnDelete();
        });

        $this->check($variants, 'status_valid', "status in ('active', 'archived')");
        $this->check($variants, 'attributes_object', "jsonb_typeof(attributes) = 'object'");
        $this->check(
            $variants,
            'dimensions_non_negative',
            '(weight_grams is null or weight_grams >= 0) and (length_mm is null or length_mm >= 0)'
            .' and (width_mm is null or width_mm >= 0) and (height_mm is null or height_mm >= 0)',
        );

        $this->create('variant_option_values', function (Blueprint $table) use ($variants, $productOptions, $optionValues): void {
            $table->uuid('variant_id');
            $table->uuid('product_id');
            $table->uuid('option_id');
            $table->uuid('option_value_id');
            $table->primary(['variant_id', 'option_id']);
            $this->index($table, ['option_value_id', 'variant_id']);
            $this->foreign($table, ['variant_id', 'product_id'])->references(['id', 'product_id'])->on($variants)->cascadeOnDelete();
            $this->foreign($table, ['product_id', 'option_id'])->references(['product_id', 'option_id'])->on($productOptions)->restrictOnDelete();
            $this->foreign($table, ['option_value_id', 'option_id'])->references(['id', 'option_id'])->on($optionValues)->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $this->drop('variant_option_values');
        $this->drop('variants');
    }
};
