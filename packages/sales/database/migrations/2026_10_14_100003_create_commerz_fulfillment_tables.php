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

        $fulfillments = $this->create('fulfillments', function (Blueprint $table) use ($orders): void {
            $this->uuidPrimary($table);
            $table->uuid('order_id');
            $table->string('status', 16)->default('created');
            $table->string('location_code', 64)->nullable();
            $table->text('carrier')->nullable(); // carrier, tracking and label come from the booking port unbounded
            $table->string('method_code', 64)->nullable();
            $table->text('tracking')->nullable();
            $table->text('label_ref')->nullable();
            $table->timestampTz('shipped_at', 6)->nullable();
            $table->timestampTz('delivered_at', 6)->nullable();
            $this->timestampsTz($table);
            $this->foreign($table, 'order_id')->references('id')->on($orders);
            $this->index($table, 'order_id');
        });

        $this->check($fulfillments, 'status_valid', "status in ('created', 'shipped', 'delivered')");
        $this->check($fulfillments, 'shipped_has_time', "status = 'created' or shipped_at is not null");
        $this->check($fulfillments, 'delivered_has_time', "status <> 'delivered' or delivered_at is not null");

        $fulfillmentLines = $this->create('fulfillment_lines', function (Blueprint $table) use ($fulfillments, $orderLines): void {
            $table->uuid('fulfillment_id');
            $table->uuid('order_line_id');
            $table->integer('quantity');
            $table->primary(['fulfillment_id', 'order_line_id']);
            $this->foreign($table, 'fulfillment_id')->references('id')->on($fulfillments)->cascadeOnDelete();
            $this->foreign($table, 'order_line_id')->references('id')->on($orderLines);
            $this->index($table, 'order_line_id');
        });

        $this->check($fulfillmentLines, 'quantity_positive', 'quantity >= 1');

        $returns = $this->create('returns', function (Blueprint $table) use ($orders): void {
            $this->uuidPrimary($table);
            $table->uuid('order_id');
            $table->string('status', 16)->default('requested');
            $table->string('reason', 191)->nullable();
            $table->string('location_code', 64)->nullable();
            $table->timestampTz('received_at', 6)->nullable();
            $table->timestampTz('cancelled_at', 6)->nullable();
            $this->timestampsTz($table);
            $this->foreign($table, 'order_id')->references('id')->on($orders);
            $this->index($table, 'order_id');
        });

        $this->check($returns, 'status_valid', "status in ('requested', 'received', 'cancelled')");
        $this->check($returns, 'received_matches_status', "(status = 'received') = (received_at is not null)");
        $this->check($returns, 'cancelled_matches_status', "(status = 'cancelled') = (cancelled_at is not null)");

        $returnLines = $this->create('return_lines', function (Blueprint $table) use ($returns, $orderLines): void {
            $table->uuid('return_id');
            $table->uuid('order_line_id');
            $table->integer('quantity');
            $table->integer('restocked_quantity')->default(0);
            $table->primary(['return_id', 'order_line_id']);
            $this->foreign($table, 'return_id')->references('id')->on($returns)->cascadeOnDelete();
            $this->foreign($table, 'order_line_id')->references('id')->on($orderLines);
        });

        $this->check($returnLines, 'quantity_positive', 'quantity >= 1');
        $this->check($returnLines, 'restocked_within_quantity', 'restocked_quantity between 0 and quantity');
    }

    public function down(): void
    {
        $this->drop('return_lines');
        $this->drop('returns');
        $this->drop('fulfillment_lines');
        $this->drop('fulfillments');
    }
};
