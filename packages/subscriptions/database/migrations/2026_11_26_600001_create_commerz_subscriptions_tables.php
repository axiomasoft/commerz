<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $contracts = $this->create('subscription_contracts', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->uuid('channel_id');
            $table->char('currency', 3);
            $table->string('locale', 16)->default('en');
            $table->string('customer_ref', 191);
            $table->string('status', 16)->default('active');
            $table->string('payment_method_ref', 191)->nullable();
            $table->string('origin_order_ref', 191)->nullable();
            $table->string('origin_line_id', 64)->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->jsonb('selling_plan');
            $table->jsonb('retry_policy');
            $table->integer('completed_cycles')->default(0);
            $table->integer('consecutive_failures')->default(0);
            $table->timestampTz('next_billing_at', 6)->nullable();
            $table->timestampTz('paused_at', 6)->nullable();
            $table->timestampTz('canceled_at', 6)->nullable();
            $table->string('cancel_reason', 191)->nullable();
            $table->bigInteger('version')->default(0);
            $table->jsonb('metadata')->default('{}');
            $this->timestampsTz($table);
            $this->unique($table, 'idempotency_key');
            $this->unique($table, ['origin_order_ref', 'origin_line_id']);
            $this->index($table, ['customer_ref', 'status']);
            $this->index($table, ['status', 'next_billing_at']);
            $this->index($table, 'origin_order_ref');
        });

        $this->check($contracts, 'status_valid', "status in ('active', 'paused', 'past_due', 'canceled')");
        $this->check($contracts, 'completed_cycles_non_negative', 'completed_cycles >= 0');
        $this->check($contracts, 'failures_non_negative', 'consecutive_failures >= 0');
        $this->check($contracts, 'version_non_negative', 'version >= 0');

        $lines = $this->create('subscription_contract_lines', function (Blueprint $table) use ($contracts): void {
            $this->uuidPrimary($table);
            $table->uuid('contract_id');
            $table->string('line_id', 64);
            $table->string('sellable_ref', 191);
            $table->string('sku', 191);
            $table->string('name', 191);
            $table->string('kind', 32);
            $table->boolean('requires_shipping')->default(true);
            $table->integer('quantity');
            $table->bigInteger('base_unit_price_minor');
            $table->string('tax_class', 64)->nullable();
            $table->integer('weight_grams')->nullable();
            $table->jsonb('snapshot')->default('{}');
            $this->timestampsTz($table);
            $this->unique($table, ['contract_id', 'line_id']);
            $this->index($table, 'sellable_ref');
            $this->foreign($table, 'contract_id')->references('id')->on($contracts)->cascadeOnDelete();
        });

        $this->check($lines, 'quantity_positive', 'quantity >= 1');
        $this->check($lines, 'price_non_negative', 'base_unit_price_minor >= 0');

        $cycles = $this->create('subscription_billing_cycles', function (Blueprint $table) use ($contracts): void {
            $this->uuidPrimary($table);
            $table->uuid('contract_id');
            $table->integer('cycle_number');
            $table->string('status', 16)->default('scheduled');
            $table->timestampTz('scheduled_at', 6);
            $table->timestampTz('next_attempt_at', 6)->nullable();
            $table->integer('attempts')->default(0);
            $table->char('currency', 3);
            $table->bigInteger('billed_amount_minor')->nullable();
            $table->string('order_ref', 191)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestampTz('billed_at', 6)->nullable();
            $this->timestampsTz($table);
            $this->unique($table, ['contract_id', 'cycle_number']);
            $this->index($table, ['status', 'next_attempt_at']);
            $this->index($table, 'order_ref');
            $this->foreign($table, 'contract_id')->references('id')->on($contracts)->restrictOnDelete();
        });

        $this->check($cycles, 'cycle_positive', 'cycle_number >= 1');
        $this->check($cycles, 'status_valid', "status in ('scheduled', 'retrying', 'billed', 'skipped', 'canceled', 'failed')");
        $this->check($cycles, 'attempts_non_negative', 'attempts >= 0');
        $this->check($cycles, 'billed_amount_non_negative', 'billed_amount_minor is null or billed_amount_minor >= 0');

        $renewals = $this->create('subscription_renewal_orders', function (Blueprint $table) use ($contracts, $cycles): void {
            $this->uuidPrimary($table);
            $table->string('order_ref', 191);
            $table->uuid('contract_id');
            $table->uuid('cycle_id');
            $table->integer('cycle_number');
            $table->uuid('channel_id');
            $table->string('customer_ref', 191);
            $table->string('payment_method_ref', 191)->nullable();
            $table->char('currency', 3);
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('discount_total_minor');
            $table->bigInteger('delivery_total_minor');
            $table->bigInteger('tax_total_minor');
            $table->bigInteger('total_minor');
            $table->bigInteger('due_now_minor');
            $table->string('idempotency_key', 191);
            $table->jsonb('lines_snapshot');
            $table->timestampTz('created_at', 6);
            $this->unique($table, 'order_ref');
            $this->unique($table, 'idempotency_key');
            $this->unique($table, 'cycle_id');
            $this->index($table, ['contract_id', 'cycle_number']);
            $this->foreign($table, 'contract_id')->references('id')->on($contracts)->restrictOnDelete();
            $this->foreign($table, 'cycle_id')->references('id')->on($cycles)->restrictOnDelete();
        });

        $this->check($renewals, 'cycle_positive', 'cycle_number >= 2');
        $this->check($renewals, 'total_non_negative', 'total_minor >= 0');
        $this->check($renewals, 'due_now_non_negative', 'due_now_minor >= 0');

        $this->create('subscription_processed_events', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->uuid('event_id');
            $table->string('event_type', 191);
            $table->string('aggregate_ref', 191);
            $table->timestampTz('processed_at', 6);
            $this->unique($table, 'event_id');
            $this->index($table, 'aggregate_ref');
        });
    }

    public function down(): void
    {
        $this->drop('subscription_processed_events');
        $this->drop('subscription_renewal_orders');
        $this->drop('subscription_billing_cycles');
        $this->drop('subscription_contract_lines');
        $this->drop('subscription_contracts');
    }
};
