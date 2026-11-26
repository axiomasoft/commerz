<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends CommerzMigration
{
    private const string GATEWAY = "'^[a-z0-9][a-z0-9_.-]{0,63}$'";

    public function up(): void
    {
        $orders = $this->table('orders');

        $collections = $this->create('payment_collections', function (Blueprint $table) use ($orders): void {
            $this->uuidPrimary($table);
            $table->string('kind', 16);
            $table->string('target_ref', 191);
            $table->uuid('order_id')->nullable();
            $table->string('status', 32);
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->bigInteger('authorized_minor')->default(0);
            $table->bigInteger('captured_minor')->default(0);
            $this->ref($table, 'customer', nullable: true);
            $table->string('customer_email', 191)->nullable();
            $table->jsonb('meta')->default('{}');
            $this->timestampsTz($table);
            $this->foreign($table, 'order_id')->references('id')->on($orders);
            $this->index($table, 'order_id');
            $this->index($table, 'target_ref');
            $this->index($table, 'status');
        });

        $this->check($collections, 'kind_valid', "kind in ('order', 'top_up')");
        $this->check($collections, 'status_valid', "status in ('not_paid', 'partially_authorized', 'authorized', 'partially_captured', 'captured', 'cancelled')");
        $this->check($collections, 'amount_positive', 'amount_minor > 0');
        $this->check($collections, 'authorized_non_negative', 'authorized_minor >= 0');
        $this->check($collections, 'captured_non_negative', 'captured_minor >= 0');
        $this->check($collections, 'currency_valid', "currency ~ '^[A-Z]{3}$'");
        $this->check($collections, 'meta_object', "jsonb_typeof(meta) = 'object'");

        $sessions = $this->create('payment_sessions', function (Blueprint $table) use ($collections): void {
            $this->uuidPrimary($table);
            $table->uuid('collection_id');
            $table->string('kind', 16);
            $table->string('gateway', 64)->nullable();
            $table->string('stored_value_kind', 16)->nullable();
            $table->string('stored_value_account_id', 191)->nullable();
            $table->string('stored_value_hold_id', 191)->nullable();
            $table->string('reference', 191);
            $table->string('external_id', 191)->nullable();
            $table->string('status', 32);
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->bigInteger('authorized_minor')->default(0);
            $table->bigInteger('captured_minor')->default(0);
            $table->boolean('capture_closed')->default(false);
            $table->text('action_url')->nullable();
            $table->jsonb('meta')->default('{}');
            $table->char('fingerprint', 64);
            $table->integer('attempts')->default(1);
            $table->timestampTz('claimed_at', 6);
            $table->string('error_code', 128)->nullable();
            $table->text('error_message')->nullable();
            $this->timestampsTz($table);
            $this->foreign($table, 'collection_id')->references('id')->on($collections);
            $this->unique($table, 'reference');
            $this->unique($table, ['gateway', 'external_id']);
            $this->index($table, 'collection_id');
            $this->index($table, 'status');
        });

        $this->check($sessions, 'kind_valid', "kind in ('gateway', 'stored_value')");
        $this->check(
            $sessions,
            'provider_matches_kind',
            "(kind = 'gateway' and gateway is not null and stored_value_kind is null and stored_value_account_id is null)"
            ." or (kind = 'stored_value' and gateway is null and stored_value_kind in ('gift_card', 'wallet') and stored_value_account_id is not null)",
        );
        $this->check($sessions, 'status_valid', "status in ('pending', 'authorizing', 'requires_action', 'authorized', 'partially_captured', 'captured', 'voided', 'failed', 'unknown')");
        $this->check($sessions, 'amount_positive', 'amount_minor > 0');
        $this->check($sessions, 'authorized_non_negative', 'authorized_minor >= 0');
        $this->check($sessions, 'captured_non_negative', 'captured_minor >= 0');
        $this->check($sessions, 'attempts_positive', 'attempts >= 1');
        $this->check($sessions, 'gateway_valid', 'gateway is null or gateway ~ '.self::GATEWAY);
        $this->check($sessions, 'currency_valid', "currency ~ '^[A-Z]{3}$'");
        $this->check($sessions, 'meta_object', "jsonb_typeof(meta) = 'object'");

        $captures = $this->create('payment_captures', function (Blueprint $table) use ($collections, $sessions): void {
            $this->uuidPrimary($table);
            $table->uuid('session_id');
            $table->uuid('collection_id');
            $table->string('reference', 191);
            $table->string('external_id', 191)->nullable();
            $table->string('status', 16);
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->boolean('is_final_capture')->default(true);
            $table->jsonb('meta')->default('{}');
            $table->char('fingerprint', 64);
            $table->integer('attempts')->default(1);
            $table->timestampTz('claimed_at', 6);
            $table->string('error_code', 128)->nullable();
            $table->text('error_message')->nullable();
            $this->timestampsTz($table);
            $this->foreign($table, 'session_id')->references('id')->on($sessions);
            $this->foreign($table, 'collection_id')->references('id')->on($collections);
            $this->unique($table, 'reference');
            $this->index($table, 'session_id');
            $this->index($table, 'collection_id');
        });

        $this->check($captures, 'status_valid', "status in ('initiating', 'pending', 'succeeded', 'failed', 'unknown')");
        $this->check($captures, 'amount_positive', 'amount_minor > 0');
        $this->check($captures, 'attempts_positive', 'attempts >= 1');
        $this->check($captures, 'currency_valid', "currency ~ '^[A-Z]{3}$'");
        $this->check($captures, 'meta_object', "jsonb_typeof(meta) = 'object'");
    }

    public function down(): void
    {
        $this->drop('payment_captures');
        $this->drop('payment_sessions');
        $this->drop('payment_collections');
    }
};
