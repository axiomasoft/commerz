<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $orders = $this->table('orders');
        $orderLines = $this->table('order_lines');
        $fulfillments = $this->table('fulfillments');

        $fulfillmentOrders = $this->create('fulfillment_orders', function (Blueprint $table) use ($orders, $fulfillments): void {
            $this->uuidPrimary($table);
            $table->uuid('order_id');
            $table->string('location_code', 64);
            $table->string('status', 16)->default('open');
            $table->jsonb('constraints')->default('{}');
            $table->uuid('fulfillment_id')->nullable();
            $table->timestampTz('assigned_at', 6);
            $table->timestampTz('fulfilled_at', 6)->nullable();
            $table->timestampTz('cancelled_at', 6)->nullable();
            $this->timestampsTz($table);
            $this->foreign($table, 'order_id')->references('id')->on($orders);
            $this->foreign($table, 'fulfillment_id')->references('id')->on($fulfillments)->nullOnDelete();
            $this->index($table, 'order_id');
            $this->index($table, ['location_code', 'status']);
            $this->index($table, 'fulfillment_id');
        });

        $this->check($fulfillmentOrders, 'status_valid', "status in ('open', 'in_progress', 'on_hold', 'fulfilled', 'cancelled')");
        $this->check($fulfillmentOrders, 'fulfilled_matches_status', "(status = 'fulfilled') = (fulfilled_at is not null)");
        $this->check($fulfillmentOrders, 'cancelled_matches_status', "(status = 'cancelled') = (cancelled_at is not null)");
        $this->check($fulfillmentOrders, 'location_code_valid', "location_code ~ '^[a-z0-9][a-z0-9_.-]{0,63}$'");

        $fulfillmentOrderLines = $this->create('fulfillment_order_lines', function (Blueprint $table) use ($fulfillmentOrders, $orderLines): void {
            $table->uuid('fulfillment_order_id');
            $table->uuid('order_line_id');
            $table->integer('quantity');
            $table->primary(['fulfillment_order_id', 'order_line_id']);
            $this->foreign($table, 'fulfillment_order_id')->references('id')->on($fulfillmentOrders)->cascadeOnDelete();
            $this->foreign($table, 'order_line_id')->references('id')->on($orderLines);
            $this->index($table, 'order_line_id');
        });

        $this->check($fulfillmentOrderLines, 'quantity_positive', 'quantity >= 1');
    }

    public function down(): void
    {
        $this->drop('fulfillment_order_lines');
        $this->drop('fulfillment_orders');
    }
};
