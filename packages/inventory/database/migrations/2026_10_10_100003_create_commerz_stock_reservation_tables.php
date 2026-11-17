<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Packman\Schema\Data\Compiler\ConstraintNames;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        $items = $this->table('stock_items');
        $locations = $this->table('stock_locations');
        $levels = $this->table('stock_levels');

        $reservations = $this->create('stock_reservations', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->string('demand', 191);
            $table->uuid('channel_id')->nullable();
            $table->string('state', 16);
            $table->integer('generation')->default(1);
            $table->timestampTz('expires_at', 6)->nullable();
            $this->timestampsTz($table);
            $this->unique($table, 'demand');
        });

        $this->check($reservations, 'state_valid', "state in ('active', 'closed')");

        $expiry = ConstraintNames::index($reservations, 'expires_at');
        $this->db()->statement("create index \"{$expiry}\" on \"{$reservations}\" (expires_at) where state = 'active' and expires_at is not null");

        $lines = $this->create('stock_reservation_lines', function (Blueprint $table) use ($reservations, $items, $locations, $levels): void {
            $this->uuidPrimary($table);
            $table->uuid('reservation_id');
            $this->ref($table, 'sellable', nullable: true);
            $table->uuid('stock_item_id');
            $table->uuid('location_id');
            $table->uuid('stock_level_id');
            $table->integer('generation');
            $table->bigInteger('quantity_per_unit');
            $table->bigInteger('units');
            $table->bigInteger('consumed_units')->default(0);
            $table->bigInteger('released_units')->default(0);
            $table->bigInteger('expired_units')->default(0);
            $table->integer('position');
            $this->timestampsTz($table);
            $this->foreign($table, 'reservation_id')->references('id')->on($reservations)->cascadeOnDelete();
            $this->foreign($table, 'stock_item_id')->references('id')->on($items)->restrictOnDelete();
            $this->foreign($table, 'location_id')->references('id')->on($locations)->restrictOnDelete();
            $this->foreign($table, 'stock_level_id')->references('id')->on($levels)->restrictOnDelete();
        });

        $this->check($lines, 'units_positive', 'units > 0 and quantity_per_unit > 0');
        $this->check($lines, 'counters_non_negative', 'consumed_units >= 0 and released_units >= 0 and expired_units >= 0');
        $this->check($lines, 'counters_within_units', 'consumed_units + released_units + expired_units <= units');

        $columns = ['reservation_id', 'generation', 'sellable_ref', 'stock_level_id'];
        $unique = ConstraintNames::unique($lines, $columns);
        $this->db()->statement("alter table \"{$lines}\" add constraint \"{$unique}\" unique nulls not distinct (".implode(', ', $columns).')');

        $outstanding = ConstraintNames::index($lines, 'stock_level_id');
        $this->db()->statement("create index \"{$outstanding}\" on \"{$lines}\" (stock_level_id) where units > consumed_units + released_units + expired_units");
    }

    public function down(): void
    {
        $this->drop('stock_reservation_lines');
        $this->drop('stock_reservations');
    }
};
