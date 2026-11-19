<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $items = $this->table('stock_items');
        $locations = $this->table('stock_locations');

        $levels = $this->create('stock_levels', function (Blueprint $table) use ($items, $locations): void {
            $this->uuidPrimary($table);
            $table->uuid('stock_item_id');
            $table->uuid('location_id');
            $table->bigInteger('on_hand')->default(0);
            $table->bigInteger('reserved')->default(0);
            $table->bigInteger('safety_stock')->default(0);
            $table->bigInteger('version')->default(0);
            $this->timestampsTz($table);
            $this->unique($table, ['stock_item_id', 'location_id']);
            $this->index($table, 'location_id');
            $this->foreign($table, 'stock_item_id')->references('id')->on($items)->restrictOnDelete();
            $this->foreign($table, 'location_id')->references('id')->on($locations)->restrictOnDelete();
        });

        $this->check($levels, 'on_hand_non_negative', 'on_hand >= 0');
        $this->check($levels, 'reserved_non_negative', 'reserved >= 0');
        $this->check($levels, 'safety_stock_non_negative', 'safety_stock >= 0');

        $movements = $this->create('stock_movements', function (Blueprint $table) use ($levels): void {
            $this->identityPrimary($table);
            $table->uuid('stock_level_id');
            $table->uuid('stock_item_id');
            $table->uuid('location_id');
            $table->string('kind', 16);
            $table->bigInteger('delta');
            $table->bigInteger('balance_after');
            $table->string('reference', 191)->nullable();
            $table->string('reason', 191)->nullable();
            $table->string('actor_ref', 191)->nullable();
            $table->timestampTz('occurred_at', 6)->useCurrent();
            $this->index($table, ['stock_level_id', 'id']);
            $this->index($table, ['stock_item_id', 'id']);
            $this->index($table, 'reference');
            $this->foreign($table, 'stock_level_id')->references('id')->on($levels)->restrictOnDelete();
        });

        $this->check($movements, 'delta_non_zero', 'delta <> 0');
        $this->check($movements, 'balance_after_non_negative', 'balance_after >= 0');
        $this->check($movements, 'kind_valid', "kind in ('receipt', 'adjustment', 'count', 'sale', 'transfer_out', 'transfer_in', 'return')");
    }

    public function down(): void
    {
        $this->drop('stock_movements');
        $this->drop('stock_levels');
    }
};
