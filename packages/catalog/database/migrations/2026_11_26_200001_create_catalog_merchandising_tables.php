<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $products = $this->table('products');
        $optionValues = $this->table('option_values');

        $brands = $this->create('brands', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->string('code', 64);
            $table->jsonb('name');
            $table->jsonb('description')->nullable();
            $table->string('logo_asset_id', 191)->nullable();
            $table->string('website_url', 500)->nullable();
            $table->string('status', 16)->default('active');
            $this->timestampsTz($table);
            $this->unique($table, 'code');
            $this->index($table, ['status', 'code']);
        });

        $this->check($brands, 'status_valid', "status in ('active', 'archived')");
        $this->check($brands, 'name_object', "jsonb_typeof(name) = 'object'");
        $this->check($brands, 'description_object', "description is null or jsonb_typeof(description) = 'object'");

        $relations = $this->create('product_relations', function (Blueprint $table) use ($products): void {
            $this->uuidPrimary($table);
            $table->uuid('source_product_id');
            $table->uuid('target_product_id');
            $table->string('relation_type', 32);
            $table->integer('position')->default(0);
            $this->timestampsTz($table);
            $this->unique($table, ['source_product_id', 'target_product_id', 'relation_type']);
            $this->index($table, ['source_product_id', 'relation_type', 'position']);
            $this->index($table, ['target_product_id', 'relation_type']);
            $this->foreign($table, 'source_product_id')->references('id')->on($products)->restrictOnDelete();
            $this->foreign($table, 'target_product_id')->references('id')->on($products)->restrictOnDelete();
        });

        $this->check($relations, 'not_self_relation', 'source_product_id <> target_product_id');
        $this->check($relations, 'relation_type_valid', "relation_type in ('upsell', 'cross_sell', 'spare_part', 'accessory', 'replacement')");
        $this->check($relations, 'position_non_negative', 'position >= 0');

        $attachments = $this->create('asset_attachments', function (Blueprint $table) use ($products, $optionValues): void {
            $this->uuidPrimary($table);
            $table->uuid('product_id');
            $table->uuid('option_value_id')->nullable();
            $table->string('asset_id', 191);
            $table->string('role', 32);
            $table->jsonb('alt')->nullable();
            $table->integer('position')->default(0);
            $this->timestampsTz($table);
            $this->index($table, ['product_id', 'role', 'position']);
            $this->index($table, ['product_id', 'option_value_id', 'position']);
            $this->foreign($table, 'product_id')->references('id')->on($products)->restrictOnDelete();
            $this->foreign($table, 'option_value_id')->references('id')->on($optionValues)->restrictOnDelete();
        });

        $this->check($attachments, 'role_valid', "role in ('thumbnail', 'gallery', 'swatch', 'video', 'document')");
        $this->check($attachments, 'position_non_negative', 'position >= 0');
        $this->check($attachments, 'alt_object', "alt is null or jsonb_typeof(alt) = 'object'");
    }

    public function down(): void
    {
        $this->drop('asset_attachments');
        $this->drop('product_relations');
        $this->drop('brands');
    }
};
