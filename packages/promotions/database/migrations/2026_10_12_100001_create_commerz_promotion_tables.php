<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        $promotions = $this->create('promotions', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->string('code', 64);
            $table->string('name', 255);
            $table->string('stage', 16);
            $table->string('activator', 64);
            $table->string('stacking', 16);
            $table->boolean('stop_further')->default(false);
            $table->integer('priority')->default(0);
            $this->money($table, 'budget', nullable: true); // budget_minor and the promotion's currency
            $table->string('status', 16)->default('active');
            $table->timestampTz('starts_at', 6)->nullable();
            $table->timestampTz('ends_at', 6)->nullable();
            $table->integer('usage_limit')->nullable();
            $table->integer('per_customer_limit')->nullable();
            $table->integer('used')->default(0); // kept only while usage_limit is set (D27)
            $table->bigInteger('spent_minor')->default(0); // kept only while budget_minor is set (D27)
            $table->integer('revision')->default(1);
            $this->timestampsTz($table);
            $this->unique($table, 'code');
            $this->index($table, ['status', 'stage']);
        });

        $this->check($promotions, 'stage_valid', "stage in ('catalog', 'cart', 'delivery')");
        $this->check($promotions, 'stacking_valid', "stacking in ('stackable', 'exclusive')");
        $this->check($promotions, 'status_valid', "status in ('active', 'paused', 'archived')");
        $this->check($promotions, 'window_ordered', 'starts_at is null or ends_at is null or starts_at < ends_at');
        $this->check($promotions, 'budget_positive', 'budget_minor is null or budget_minor > 0');
        $this->check($promotions, 'budget_has_currency', 'budget_minor is null or currency is not null');
        $this->check($promotions, 'usage_limit_positive', 'usage_limit is null or usage_limit > 0');
        $this->check($promotions, 'per_customer_limit_positive', 'per_customer_limit is null or per_customer_limit > 0');
        $this->check($promotions, 'used_non_negative', 'used >= 0');
        $this->check($promotions, 'spent_non_negative', 'spent_minor >= 0');

        $this->create('promotion_channels', function (Blueprint $table) use ($promotions): void {
            $table->uuid('promotion_id');
            $table->uuid('channel_id'); // a core table: no foreign key
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->primary(['promotion_id', 'channel_id']);
            $this->foreign($table, 'promotion_id')->references('id')->on($promotions)->cascadeOnDelete();
            $this->index($table, 'channel_id');
        });

        $rules = $this->create('promotion_rules', function (Blueprint $table) use ($promotions): void {
            $this->identityPrimary($table);
            $table->uuid('promotion_id');
            $table->string('role', 16);
            $table->integer('position');
            $table->string('rule_key', 64);
            $table->integer('config_version');
            $table->jsonb('config');
            $this->foreign($table, 'promotion_id')->references('id')->on($promotions)->cascadeOnDelete();
            $this->unique($table, ['promotion_id', 'role', 'position']);
        });

        $this->check($rules, 'role_valid', "role in ('condition', 'effect')");
        $this->check($rules, 'position_non_negative', '"position" >= 0');
        $this->check($rules, 'config_version_positive', 'config_version >= 1');

        $usages = $this->create('promotion_customer_usages', function (Blueprint $table) use ($promotions): void {
            $table->uuid('promotion_id');
            $this->ref($table, 'customer');
            $table->integer('used')->default(0); // kept only while per_customer_limit is set (D27)
            $table->timestampTz('updated_at', 6)->useCurrent();
            $table->primary(['promotion_id', 'customer_ref']);
            $this->foreign($table, 'promotion_id')->references('id')->on($promotions)->cascadeOnDelete();
        });

        $this->check($usages, 'used_non_negative', 'used >= 0');
    }

    public function down(): void
    {
        $this->drop('promotion_customer_usages');
        $this->drop('promotion_rules');
        $this->drop('promotion_channels');
        $this->drop('promotions');
    }
};
