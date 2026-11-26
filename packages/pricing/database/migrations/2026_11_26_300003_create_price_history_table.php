<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $lists = $this->table('price_lists');

        $history = $this->create('price_history', function (Blueprint $table) use ($lists): void {
            $this->identityPrimary($table);
            $table->uuid('price_id');
            $table->uuid('price_list_id');
            $this->ref($table, 'sellable');
            $table->string('customer_group', 64)->nullable();
            $table->bigInteger('min_quantity')->default(1);
            $this->money($table, 'amount');
            $this->money($table, 'compare_at', nullable: true);
            $table->string('event_type', 16);
            $table->timestampTz('valid_from', 6)->nullable();
            $table->timestampTz('valid_until', 6)->nullable();
            $table->timestampTz('recorded_at', 6);
            $this->foreign($table, 'price_list_id')->references('id')->on($lists)->restrictOnDelete();
            $this->index($table, ['sellable_ref', 'currency', 'recorded_at']);
            $this->index($table, ['price_list_id', 'sellable_ref', 'recorded_at']);
        });

        $this->check($history, 'min_quantity_positive', 'min_quantity >= 1');
        $this->check($history, 'amount_non_negative', 'amount_minor >= 0');
        $this->check($history, 'event_type_valid', "event_type in ('set', 'rescheduled', 'removed')");
        $this->check($history, 'window_ordered', 'valid_from is null or valid_until is null or valid_from < valid_until');
    }

    public function down(): void
    {
        $this->drop('price_history');
    }
};
