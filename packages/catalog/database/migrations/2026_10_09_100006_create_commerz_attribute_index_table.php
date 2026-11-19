<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $products = $this->table('products');
        $variants = $this->table('variants');
        $attributes = $this->table('attributes');
        $choices = $this->table('attribute_choices');

        $index = $this->create('attribute_index', function (Blueprint $table) use ($products, $variants, $attributes, $choices): void {
            $this->identityPrimary($table);
            $table->uuid('product_id');
            $table->uuid('variant_id')->nullable();
            $table->uuid('attribute_id');
            $table->uuid('choice_id')->nullable();
            // numeric(38,12) holds every int64 and every decimal of scale <= 12 exactly (catalog-design#attribute-types).
            $table->decimal('num', 38, 12)->nullable();
            $table->boolean('flag')->nullable();
            $table->date('day')->nullable();
            $table->string('term', 255)->nullable();
            $this->index($table, ['attribute_id', 'choice_id']);
            $this->index($table, ['attribute_id', 'num']);
            $this->index($table, ['attribute_id', 'term']);
            $this->index($table, ['attribute_id', 'day']);
            $this->index($table, ['attribute_id', 'flag']);
            $this->index($table, 'product_id');
            $this->index($table, 'variant_id');
            $this->foreign($table, 'product_id')->references('id')->on($products)->cascadeOnDelete();
            $this->foreign($table, 'variant_id')->references('id')->on($variants)->cascadeOnDelete();
            $this->foreign($table, 'attribute_id')->references('id')->on($attributes)->restrictOnDelete();
            $this->foreign($table, ['choice_id', 'attribute_id'])->references(['id', 'attribute_id'])->on($choices)->restrictOnDelete();
        });

        $this->check($index, 'one_value', 'num_nonnulls(choice_id, num, flag, day, term) = 1');
    }

    public function down(): void
    {
        $this->drop('attribute_index');
    }
};
