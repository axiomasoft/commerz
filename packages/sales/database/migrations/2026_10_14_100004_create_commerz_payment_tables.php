<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    private const string GATEWAY = "'^[a-z0-9][a-z0-9_.-]{0,63}$'";

    public function up(): void
    {
        $orders = $this->table('orders');
        $returns = $this->table('returns');

        $payments = $this->create('payments', function (Blueprint $table) use ($orders): void {
            $this->uuidPrimary($table);
            $table->uuid('order_id');
            $table->string('gateway', 64);
            $table->string('reference', 191); // `payment:{id}`: the idempotency key at the gateway
            $table->string('external_id', 191)->nullable();
            $table->string('status', 32);
            // Declared by hand: `money()` would make the currency nullable, a payment always has one.
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->bigInteger('authorized_minor')->default(0);
            $table->bigInteger('captured_minor')->default(0);
            $table->bigInteger('refunded_minor')->default(0);
            $table->text('action_url')->nullable();
            $table->jsonb('meta')->default('{}');
            $table->char('fingerprint', 64);
            $table->integer('attempts')->default(1);
            $table->timestampTz('claimed_at', 6); // the lease of the network operation (D33)
            $table->string('error_code', 128)->nullable();
            $table->text('error_message')->nullable();
            $this->timestampsTz($table);
            $this->foreign($table, 'order_id')->references('id')->on($orders);
            $this->unique($table, 'reference');
            // A plain UNIQUE: NULLs are distinct, so unfinished attempts without an external id never collide.
            $this->unique($table, ['gateway', 'external_id']);
            $this->index($table, 'order_id');
            $this->index($table, 'status');
        });

        $this->check($payments, 'status_valid', "status in ('initiating', 'pending', 'requires_action', 'authorized', 'captured', 'failed', 'cancelled', 'unknown')");
        $this->check($payments, 'amount_positive', 'amount_minor > 0');
        $this->check($payments, 'authorized_within_amount', 'authorized_minor between 0 and amount_minor');
        $this->check($payments, 'captured_within_amount', 'captured_minor between 0 and amount_minor');
        $this->check($payments, 'refunded_within_captured', 'refunded_minor between 0 and captured_minor');
        $this->check($payments, 'attempts_positive', 'attempts >= 1');
        $this->check($payments, 'gateway_valid', 'gateway ~ '.self::GATEWAY);
        $this->check($payments, 'currency_valid', "currency ~ '^[A-Z]{3}$'");
        $this->check($payments, 'meta_object', "jsonb_typeof(meta) = 'object'");

        $refunds = $this->create('refunds', function (Blueprint $table) use ($orders, $payments, $returns): void {
            $this->uuidPrimary($table);
            $table->uuid('order_id');
            $table->uuid('payment_id');
            $table->uuid('return_id')->nullable();
            $table->string('gateway', 64);
            $table->string('reference', 191); // `refund:{id}`
            $table->string('external_id', 191)->nullable();
            $table->string('status', 16);
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->string('reason', 191)->nullable();
            $table->char('fingerprint', 64);
            $table->integer('attempts')->default(1);
            $table->timestampTz('claimed_at', 6);
            $table->string('error_code', 128)->nullable();
            $table->text('error_message')->nullable();
            $this->timestampsTz($table);
            $this->foreign($table, 'order_id')->references('id')->on($orders);
            $this->foreign($table, 'payment_id')->references('id')->on($payments);
            $this->foreign($table, 'return_id')->references('id')->on($returns);
            $this->unique($table, 'reference');
            $this->unique($table, ['gateway', 'external_id']);
            $this->index($table, 'payment_id');
            $this->index($table, 'order_id');
        });

        $this->check($refunds, 'status_valid', "status in ('initiating', 'pending', 'succeeded', 'failed', 'unknown')");
        $this->check($refunds, 'amount_positive', 'amount_minor > 0');
        $this->check($refunds, 'attempts_positive', 'attempts >= 1');
        $this->check($refunds, 'currency_valid', "currency ~ '^[A-Z]{3}$'");

        $events = $this->create('payment_events', function (Blueprint $table) use ($payments, $refunds): void {
            $this->identityPrimary($table);
            $table->string('gateway', 64);
            $table->string('external_event_id', 191);
            $table->string('type', 32);
            $table->char('fingerprint', 64);
            $table->uuid('payment_id')->nullable();
            $table->uuid('refund_id')->nullable();
            $table->string('outcome', 16);
            $table->char('currency', 3)->nullable();
            $table->bigInteger('amount_minor')->nullable();
            $table->jsonb('payload')->default('{}');
            $table->timestampTz('occurred_at', 6)->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $this->foreign($table, 'payment_id')->references('id')->on($payments);
            $this->foreign($table, 'refund_id')->references('id')->on($refunds);
            $this->unique($table, ['gateway', 'external_event_id']); // PAY-01
        });

        $this->check($events, 'type_valid', "type in ('authorized', 'captured', 'failed', 'cancelled', 'refund_succeeded', 'refund_failed')");
        $this->check($events, 'outcome_valid', "outcome in ('applied', 'ignored')");
        $this->check($events, 'one_target', 'num_nonnulls(payment_id, refund_id) = 1');
        $this->check($events, 'currency_valid', "currency is null or currency ~ '^[A-Z]{3}$'");
    }

    public function down(): void
    {
        $this->drop('payment_events');
        $this->drop('refunds');
        $this->drop('payments');
    }
};
