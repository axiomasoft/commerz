<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        $items = $this->table('stock_items');
        $locations = $this->table('stock_locations');

        $transfers = $this->create('stock_transfers', function (Blueprint $table) use ($locations): void {
            $this->uuidPrimary($table);
            $table->uuid('from_location_id');
            $table->uuid('to_location_id');
            $table->string('state', 16);
            $table->string('reference', 191)->nullable();
            $table->timestampTz('dispatched_at', 6)->nullable();
            $table->timestampTz('completed_at', 6)->nullable();
            $this->timestampsTz($table);
            $this->index($table, 'state');
            $this->foreign($table, 'from_location_id')->references('id')->on($locations)->restrictOnDelete();
            $this->foreign($table, 'to_location_id')->references('id')->on($locations)->restrictOnDelete();
        });

        $this->check($transfers, 'locations_differ', 'from_location_id <> to_location_id');
        $this->check($transfers, 'state_valid', "state in ('draft', 'in_transit', 'completed', 'cancelled')");

        $lines = $this->create('stock_transfer_lines', function (Blueprint $table) use ($transfers, $items): void {
            $this->uuidPrimary($table);
            $table->uuid('transfer_id');
            $table->uuid('stock_item_id');
            $table->bigInteger('quantity');
            $table->bigInteger('received')->default(0);
            $table->bigInteger('lost')->default(0);
            $table->integer('position');
            $this->unique($table, ['transfer_id', 'stock_item_id']);
            $this->foreign($table, 'transfer_id')->references('id')->on($transfers)->cascadeOnDelete();
            $this->foreign($table, 'stock_item_id')->references('id')->on($items)->restrictOnDelete();
        });

        $this->check($lines, 'quantity_positive', 'quantity > 0');
        $this->check($lines, 'counters_within_quantity', 'received >= 0 and lost >= 0 and received + lost <= quantity');
    }

    public function down(): void
    {
        $this->drop('stock_transfer_lines');
        $this->drop('stock_transfers');
    }
};
