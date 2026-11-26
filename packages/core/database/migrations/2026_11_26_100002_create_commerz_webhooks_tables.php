<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;
use Packman\Schema\Data\Compiler\ConstraintNames;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $subscriptions = $this->create('webhook_subscriptions', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->string('code', 64);
            $table->string('target_url', 2048);
            $table->text('signing_secret');
            $table->jsonb('event_types');
            $table->string('status', 16)->default('active');
            $table->integer('max_attempts')->default(5);
            $table->integer('failure_threshold')->default(5);
            $table->integer('consecutive_failures')->default(0);
            $table->timestampTz('disabled_at', 6)->nullable();
            $table->text('disabled_reason')->nullable();
            $table->jsonb('headers')->default('{}');
            $this->timestampsTz($table);
            $this->unique($table, 'code');
            $this->index($table, 'status');
        });

        $this->check($subscriptions, 'status_valid', "status in ('active', 'paused', 'disabled')");
        $this->check($subscriptions, 'max_attempts_positive', 'max_attempts >= 1');
        $this->check($subscriptions, 'failure_threshold_positive', 'failure_threshold >= 1');
        $this->check($subscriptions, 'consecutive_failures_not_negative', 'consecutive_failures >= 0');
        $this->check($subscriptions, 'event_types_list', "jsonb_typeof(event_types) = 'array' and jsonb_array_length(event_types) > 0");
        $this->check($subscriptions, 'headers_object', "jsonb_typeof(headers) = 'object'");

        $deliveries = $this->create('webhook_deliveries', function (Blueprint $table) use ($subscriptions): void {
            $this->uuidPrimary($table);
            $table->uuid('subscription_id');
            $table->uuid('event_id');
            $table->string('event_type', 191);
            $table->smallInteger('event_version');
            $table->string('aggregate_ref', 191);
            $table->jsonb('payload');
            $table->timestampTz('occurred_at', 6);
            $table->string('status', 16)->default('pending');
            $table->integer('attempts')->default(0);
            $table->timestampTz('available_at', 6)->useCurrent();
            $table->timestampTz('delivered_at', 6)->nullable();
            $table->smallInteger('last_http_status')->nullable();
            $table->text('last_error')->nullable();
            $this->timestampsTz($table);
            $this->foreign($table, 'subscription_id')->references('id')->on($subscriptions)->cascadeOnDelete();
            $this->unique($table, ['subscription_id', 'event_id']);
            $this->index($table, 'subscription_id');
            $this->index($table, ['status', 'available_at']);
        });

        $this->check($deliveries, 'status_valid', "status in ('pending', 'delivered', 'failed')");
        $this->check($deliveries, 'attempts_not_negative', 'attempts >= 0');
        $this->check($deliveries, 'event_version_positive', 'event_version > 0');

        $pendingQueueIndex = ConstraintNames::shorten("{$deliveries}_pending_available_at_id_ix");
        $this->db()->statement(
            "create index \"{$pendingQueueIndex}\" on \"{$deliveries}\" (available_at, id) where status = 'pending'",
        );

        $inbound = $this->create('webhook_inbound_events', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->string('source', 64);
            $table->string('external_event_id', 191);
            $table->string('event_type', 191);
            $table->char('payload_hash', 64);
            $table->jsonb('payload');
            $table->jsonb('headers')->default('{}');
            $table->string('status', 16)->default('processed');
            $table->timestampTz('received_at', 6)->useCurrent();
            $table->timestampTz('processed_at', 6)->nullable();
            $this->unique($table, ['source', 'external_event_id']);
            $this->index($table, ['source', 'received_at']);
        });

        $this->check($inbound, 'status_valid', "status in ('processed', 'ignored')");
        $this->check($inbound, 'source_valid', "source ~ '^[a-z0-9][a-z0-9_.-]{0,63}$'");
    }

    public function down(): void
    {
        $this->drop('webhook_inbound_events');
        $this->drop('webhook_deliveries');
        $this->drop('webhook_subscriptions');
    }
};
