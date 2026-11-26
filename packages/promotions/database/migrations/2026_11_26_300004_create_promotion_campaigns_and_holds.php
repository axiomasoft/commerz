<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $campaigns = $this->create('campaigns', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->string('code', 64);
            $table->string('name', 255);
            $table->string('status', 16)->default('active');
            $table->timestampTz('starts_at', 6)->nullable();
            $table->timestampTz('ends_at', 6)->nullable();
            $table->char('currency', 3)->nullable();
            $table->bigInteger('spend_cap_minor')->nullable();
            $table->integer('usage_cap')->nullable();
            $table->bigInteger('spent_minor')->default(0);
            $table->integer('used')->default(0);
            $this->timestampsTz($table);
            $this->unique($table, 'code');
            $this->index($table, 'status');
        });

        $this->check($campaigns, 'status_valid', "status in ('active', 'paused', 'archived')");
        $this->check($campaigns, 'window_ordered', 'starts_at is null or ends_at is null or starts_at < ends_at');
        $this->check($campaigns, 'spend_cap_positive', 'spend_cap_minor is null or spend_cap_minor > 0');
        $this->check($campaigns, 'spend_cap_has_currency', 'spend_cap_minor is null or currency is not null');
        $this->check($campaigns, 'usage_cap_positive', 'usage_cap is null or usage_cap > 0');
        $this->check($campaigns, 'spent_non_negative', 'spent_minor >= 0');
        $this->check($campaigns, 'used_non_negative', 'used >= 0');

        $promotions = $this->table('promotions');
        $this->schema()->table($promotions, function (Blueprint $table) use ($campaigns): void {
            $table->uuid('campaign_id')->nullable();
            $this->foreign($table, 'campaign_id')->references('id')->on($campaigns)->restrictOnDelete();
            $this->index($table, 'campaign_id');
        });

        $usages = $this->table('promotion_usages');
        $this->schema()->table($usages, function (Blueprint $table) use ($campaigns): void {
            $table->uuid('campaign_id')->nullable();
            $this->foreign($table, 'campaign_id')->references('id')->on($campaigns)->restrictOnDelete();
            $this->index($table, 'campaign_id');
        });

        $coupons = $this->table('coupons');
        $this->schema()->table($coupons, function (Blueprint $table): void {
            $table->integer('held')->default(0);
        });
        $this->check($coupons, 'held_non_negative', 'held >= 0');

        $holds = $this->create('coupon_holds', function (Blueprint $table) use ($coupons, $promotions): void {
            $this->uuidPrimary($table);
            $table->uuid('coupon_id');
            $table->uuid('promotion_id');
            $table->string('hold_ref', 191);
            $this->ref($table, 'customer', nullable: true);
            $this->ref($table, 'order', nullable: true);
            $table->string('state', 16)->default('held');
            $table->timestampTz('held_at', 6);
            $table->timestampTz('expires_at', 6);
            $table->timestampTz('redeemed_at', 6)->nullable();
            $table->timestampTz('released_at', 6)->nullable();
            $this->foreign($table, 'coupon_id')->references('id')->on($coupons)->restrictOnDelete();
            $this->foreign($table, 'promotion_id')->references('id')->on($promotions)->restrictOnDelete();
            $this->unique($table, ['coupon_id', 'hold_ref']);
            $this->index($table, ['state', 'expires_at']);
            $this->index($table, ['coupon_id', 'state', 'expires_at']);
            $this->index($table, 'order_ref');
        });

        $this->check($holds, 'state_valid', "state in ('held', 'redeemed', 'released')");
        $this->check($holds, 'expires_after_held', 'expires_at > held_at');
        $this->check($holds, 'redeemed_consistent', "(state = 'redeemed') = (redeemed_at is not null)");
        $this->check($holds, 'released_consistent', "(state = 'released') = (released_at is not null)");
        $this->check($holds, 'order_on_redeemed', "state <> 'redeemed' or order_ref is not null");
    }

    public function down(): void
    {
        $this->drop('coupon_holds');

        $this->schema()->table($this->table('coupons'), function (Blueprint $table): void {
            $table->dropColumn('held');
        });

        $this->schema()->table($this->table('promotion_usages'), function (Blueprint $table): void {
            $table->dropColumn('campaign_id');
        });

        $this->schema()->table($this->table('promotions'), function (Blueprint $table): void {
            $table->dropColumn('campaign_id');
        });

        $this->drop('campaigns');
    }
};
