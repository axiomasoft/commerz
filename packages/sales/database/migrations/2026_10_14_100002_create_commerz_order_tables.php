<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $carts = $this->table('carts');

        $orders = $this->create('orders', function (Blueprint $table) use ($carts): void {
            $this->uuidPrimary($table);
            $table->string('number', 64);
            $table->uuid('store_id'); // a core table: no foreign key
            $table->uuid('channel_id'); // a core table: no foreign key
            $table->uuid('cart_id');
            $table->string('origin', 16); // the kind of the cart: cart | draft
            $table->string('state', 16);
            $table->string('payment_state', 32);
            $table->string('fulfillment_state', 32);
            $table->string('cancel_reason', 32)->nullable();
            // Declared by hand: `money()` would make the currency nullable, an order always has one.
            $table->char('currency', 3);
            $table->string('locale', 35);
            $this->ref($table, 'customer', nullable: true);
            $table->jsonb('customer_groups');
            $table->string('customer_email', 191)->nullable();
            $table->string('customer_name', 191)->nullable();
            $table->jsonb('codes');
            $table->jsonb('shipping_address')->nullable();
            $table->jsonb('billing_address')->nullable();
            $table->jsonb('delivery')->nullable(); // DeliveryCharge
            $table->jsonb('order_adjustments');
            $table->jsonb('delivery_adjustments');
            $table->jsonb('delivery_tax_lines');
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('discount_total_minor');
            $table->bigInteger('delivery_total_minor');
            $table->bigInteger('tax_total_minor');
            $table->bigInteger('total_minor');
            $table->bigInteger('amount_due_minor');
            $table->bigInteger('authorized_minor')->default(0);
            $table->bigInteger('captured_minor')->default(0);
            $table->bigInteger('refunded_minor')->default(0);
            $table->char('quote_fingerprint', 64);
            $table->timestampTz('payment_due_at', 6)->nullable();
            $table->timestampTz('placed_at', 6);
            $table->timestampTz('completed_at', 6)->nullable();
            $table->timestampTz('cancelled_at', 6)->nullable();
            $this->ref($table, 'created_by', nullable: true);
            $this->timestampsTz($table);
            $this->foreign($table, 'cart_id')->references('id')->on($carts);
            $this->unique($table, 'cart_id'); // ORD-01
            $this->unique($table, ['store_id', 'number']);
            $this->index($table, ['state', 'payment_due_at']);
            $this->index($table, ['customer_ref', 'placed_at']);
            $this->index($table, ['channel_id', 'placed_at']);
        });

        $this->check($orders, 'state_valid', "state in ('placed', 'completed', 'cancelled')");
        $this->check($orders, 'origin_valid', "origin in ('cart', 'draft')");
        $this->check($orders, 'payment_state_valid', "payment_state in ('unpaid', 'authorized', 'partially_paid', 'paid', 'partially_refunded', 'refunded')");
        $this->check($orders, 'fulfillment_state_valid', "fulfillment_state in ('unfulfilled', 'partially_fulfilled', 'fulfilled', 'partially_returned', 'returned')");
        $this->check($orders, 'cancel_reason_valid', "cancel_reason is null or cancel_reason in ('customer', 'operator', 'expired', 'out_of_stock', 'fraud', 'other')");
        $this->check($orders, 'cancelled_matches_state', "(state = 'cancelled') = (cancelled_at is not null and cancel_reason is not null)");
        $this->check($orders, 'completed_matches_state', "(state = 'completed') = (completed_at is not null)");
        $this->check($orders, 'amounts_valid', 'total_minor >= 0 and amount_due_minor between 0 and total_minor');
        $this->check($orders, 'payments_valid', 'authorized_minor >= 0 and captured_minor >= 0 and refunded_minor between 0 and captured_minor');
        $this->check($orders, 'currency_valid', "currency ~ '^[A-Z]{3}$'");
        $this->check($orders, 'number_valid', "number ~ '^[A-Za-z0-9][A-Za-z0-9-]{0,63}$'");

        $lines = $this->create('order_lines', function (Blueprint $table) use ($orders): void {
            $this->uuidPrimary($table); // equals the id of the cart line, so quote line ids stay valid in the order
            $table->uuid('order_id');
            $table->integer('position');
            $this->ref($table, 'sellable');
            $table->string('sku', 191);
            $table->string('name', 191);
            $table->string('kind', 16);
            $table->boolean('requires_shipping');
            $table->jsonb('options');
            $table->string('tax_class', 64)->nullable();
            $table->integer('weight_grams')->nullable();
            $table->integer('quantity');
            $table->char('currency', 3);
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('compare_at_minor')->nullable();
            $table->boolean('tax_included');
            $table->string('price_source', 191);
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('total_minor');
            $table->jsonb('adjustments');
            $table->jsonb('tax_lines');
            $table->integer('cancelled_quantity')->default(0);
            $table->integer('fulfilled_quantity')->default(0);
            $table->integer('returned_quantity')->default(0);
            $this->foreign($table, 'order_id')->references('id')->on($orders)->cascadeOnDelete();
            $this->index($table, ['order_id', 'position']);
            $this->index($table, 'sellable_ref');
        });

        $this->check($lines, 'quantity_positive', 'quantity >= 1');
        $this->check($lines, 'kind_valid', "kind in ('physical', 'digital', 'service')");
        $this->check($lines, 'counters_non_negative', 'cancelled_quantity >= 0 and fulfilled_quantity >= 0 and returned_quantity >= 0');
        $this->check($lines, 'ledger_within_quantity', 'cancelled_quantity + fulfilled_quantity <= quantity'); // ORD-03
        $this->check($lines, 'returned_within_fulfilled', 'returned_quantity <= fulfilled_quantity');
        $this->check($lines, 'currency_valid', "currency ~ '^[A-Z]{3}$'");

        $events = $this->create('order_events', function (Blueprint $table) use ($orders): void {
            $this->identityPrimary($table);
            $table->uuid('order_id');
            $table->string('type', 64);
            $this->ref($table, 'actor', nullable: true);
            $table->jsonb('data')->default('{}');
            $table->timestampTz('created_at', 6)->useCurrent();
            $this->foreign($table, 'order_id')->references('id')->on($orders)->cascadeOnDelete();
            $this->index($table, ['order_id', 'id']);
        });

        $this->check($events, 'type_valid', "type ~ '^[a-z][a-z0-9_]*$'");

        // Order numbers of the built-in `sequence` generator; gaps after a rollback are fine, an order number is not an invoice number.
        // `migrate:fresh` drops tables but not standalone sequences, so a stale one from the previous install is replaced.
        $sequence = $this->table('order_number_seq');
        $this->db()->statement("drop sequence if exists \"{$sequence}\"");
        $this->db()->statement("create sequence \"{$sequence}\" start 1001");
    }

    public function down(): void
    {
        $this->db()->statement("drop sequence if exists \"{$this->table('order_number_seq')}\"");
        $this->drop('order_events');
        $this->drop('order_lines');
        $this->drop('orders');
    }
};
