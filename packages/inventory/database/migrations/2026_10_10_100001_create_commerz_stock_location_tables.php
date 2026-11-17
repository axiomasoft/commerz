<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        $locations = $this->create('stock_locations', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->string('code', 64);
            $table->string('name', 191);
            $table->string('kind', 16);
            $table->string('status', 16)->default('active');
            $this->timestampsTz($table);
            $this->unique($table, 'code');
        });

        $this->check($locations, 'kind_valid', "kind in ('warehouse', 'store', 'pickup_point')");
        $this->check($locations, 'status_valid', "status in ('active', 'archived')");

        $items = $this->create('stock_items', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->string('sku', 191);
            $table->string('name', 191)->nullable();
            $table->string('unit', 16);
            $table->string('policy', 16)->default('tracked');
            $table->string('status', 16)->default('active');
            $this->timestampsTz($table);
            $this->unique($table, 'sku');
        });

        $this->check($items, 'policy_valid', "policy in ('tracked', 'backorder', 'untracked')");
        $this->check($items, 'status_valid', "status in ('active', 'archived')");

        $links = $this->create('stock_item_links', function (Blueprint $table) use ($items): void {
            $this->uuidPrimary($table);
            $this->ref($table, 'sellable');
            $table->uuid('stock_item_id');
            $table->bigInteger('quantity_per_unit');
            $this->timestampsTz($table);
            $this->unique($table, ['sellable_ref', 'stock_item_id']);
            $this->index($table, 'stock_item_id');
            $this->foreign($table, 'stock_item_id')->references('id')->on($items)->restrictOnDelete();
        });

        $this->check($links, 'quantity_per_unit_positive', 'quantity_per_unit > 0');

        $sources = $this->create('stock_sources', function (Blueprint $table) use ($locations): void {
            $this->uuidPrimary($table);
            $table->uuid('channel_id');
            $table->uuid('location_id');
            $table->integer('priority');
            $this->timestampsTz($table);
            $this->unique($table, ['channel_id', 'location_id']);
            $this->index($table, 'location_id');
            $this->foreign($table, 'location_id')->references('id')->on($locations)->restrictOnDelete();
        });

        $this->check($sources, 'priority_range', 'priority between 0 and 1000000');
    }

    public function down(): void
    {
        $this->drop('stock_sources');
        $this->drop('stock_item_links');
        $this->drop('stock_items');
        $this->drop('stock_locations');
    }
};
