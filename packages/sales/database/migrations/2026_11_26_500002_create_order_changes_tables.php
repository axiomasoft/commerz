<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $orders = $this->table('orders');

        $this->schema()->table($orders, static function (Blueprint $table): void {
            $table->integer('version')->default(1);
        });

        $this->check($orders, 'version_positive', 'version >= 1');

        $changes = $this->create('order_changes', function (Blueprint $table) use ($orders): void {
            $this->uuidPrimary($table);
            $table->uuid('order_id');
            $table->string('status', 16)->default('draft');
            $table->integer('base_order_version');
            $table->integer('confirmed_order_version')->nullable();
            // Declared by hand: `money()` would make currency nullable, an order change always has one.
            $table->char('currency', 3);
            $table->bigInteger('amount_delta_minor')->default(0);
            $table->bigInteger('tax_delta_minor')->default(0);
            $table->text('description')->nullable();
            $table->string('compensation_strategy', 32)->nullable();
            $table->jsonb('compensation_result')->default('{}');
            $table->jsonb('excerpt_before')->nullable();
            $table->jsonb('excerpt_after')->nullable();
            $this->ref($table, 'created_by', nullable: true);
            $this->ref($table, 'confirmed_by', nullable: true);
            $table->timestampTz('confirmed_at', 6)->nullable();
            $table->timestampTz('cancelled_at', 6)->nullable();
            $this->timestampsTz($table);
            $this->foreign($table, 'order_id')->references('id')->on($orders)->cascadeOnDelete();
            $this->index($table, ['order_id', 'status']);
            $this->index($table, ['order_id', 'created_at']);
        });

        $this->check($changes, 'status_valid', "status in ('draft', 'confirmed', 'cancelled')");
        $this->check($changes, 'base_version_positive', 'base_order_version >= 1');
        $this->check($changes, 'confirmed_version_valid', 'confirmed_order_version is null or confirmed_order_version > base_order_version');
        $this->check($changes, 'confirmed_matches_status', "(status = 'confirmed') = (confirmed_at is not null and confirmed_order_version is not null)");
        $this->check($changes, 'cancelled_matches_status', "(status = 'cancelled') = (cancelled_at is not null)");
        $this->check($changes, 'currency_valid', "currency ~ '^[A-Z]{3}$'");
        $this->check($changes, 'compensation_strategy_valid', "compensation_strategy is null or compensation_strategy in ('none', 'increment_authorization', 'delta_charge', 'partial_refund', 'wallet_credit', 'deferred')");
        $this->check($changes, 'compensation_result_object', "jsonb_typeof(compensation_result) = 'object'");

        $actions = $this->create('order_change_actions', function (Blueprint $table) use ($changes): void {
            $this->uuidPrimary($table);
            $table->uuid('order_change_id');
            $table->integer('position');
            $table->string('type', 32);
            $table->jsonb('payload');
            $table->timestampTz('created_at', 6);
            $this->foreign($table, 'order_change_id')->references('id')->on($changes)->cascadeOnDelete();
            $this->unique($table, ['order_change_id', 'position']);
        });

        $this->check($actions, 'position_positive', 'position >= 1');
        $this->check($actions, 'type_valid', "type in ('add_line', 'change_line_quantity', 'remove_line', 'override_unit_price', 'change_shipping_method', 'replace_adjustments')");
        $this->check($actions, 'payload_object', "jsonb_typeof(payload) = 'object'");
    }

    public function down(): void
    {
        $this->drop('order_change_actions');
        $this->drop('order_changes');

        $this->schema()->table($this->table('orders'), static function (Blueprint $table): void {
            $table->dropColumn('version');
        });
    }
};
