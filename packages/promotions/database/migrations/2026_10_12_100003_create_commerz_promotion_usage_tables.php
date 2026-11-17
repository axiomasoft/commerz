<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        $redemptions = $this->create('promotion_redemptions', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $this->ref($table, 'order');
            $this->ref($table, 'customer', nullable: true);
            $table->char('fingerprint', 64);
            $table->string('state', 16);
            $table->string('restore_reason', 16)->nullable();
            $table->timestampTz('consumed_at', 6);
            $table->timestampTz('restored_at', 6)->nullable();
            $this->unique($table, 'order_ref');
        });

        $this->check($redemptions, 'state_valid', "state in ('consumed', 'restored')");
        $this->check($redemptions, 'restore_reason_valid', "restore_reason is null or restore_reason in ('cancelled', 'expired', 'refunded')");
        $this->check($redemptions, 'restored_consistent', "(state = 'restored') = (restored_at is not null)");

        $usages = $this->create('promotion_usages', function (Blueprint $table) use ($redemptions): void {
            $this->identityPrimary($table);
            $table->uuid('redemption_id');
            $table->uuid('promotion_id');
            $table->uuid('coupon_id')->nullable();
            $this->money($table, 'discount');
            $table->timestampTz('created_at', 6)->useCurrent();
            $this->foreign($table, 'redemption_id')->references('id')->on($redemptions)->cascadeOnDelete();
            // Restrict, and not just integrity: an insert takes FOR KEY SHARE on the promotion row, which the usage protocol (D27) relies on.
            $this->foreign($table, 'promotion_id')->references('id')->on($this->table('promotions'))->restrictOnDelete();
            $this->foreign($table, 'coupon_id')->references('id')->on($this->table('coupons'))->restrictOnDelete();
            $this->unique($table, ['redemption_id', 'promotion_id']);
            $this->index($table, 'promotion_id');
            $this->index($table, 'coupon_id');
        });

        $this->check($usages, 'discount_non_negative', 'discount_minor >= 0');
    }

    public function down(): void
    {
        $this->drop('promotion_usages');
        $this->drop('promotion_redemptions');
    }
};
