<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $promotions = $this->table('promotions');

        $batches = $this->create('coupon_batches', function (Blueprint $table) use ($promotions): void {
            $this->uuidPrimary($table);
            $table->uuid('promotion_id');
            $table->string('pattern', 64);
            $table->integer('count');
            $table->integer('usage_limit')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $this->foreign($table, 'promotion_id')->references('id')->on($promotions)->restrictOnDelete();
            $this->index($table, 'promotion_id');
        });

        $this->check($batches, 'count_positive', 'count > 0');
        $this->check($batches, 'usage_limit_positive', 'usage_limit is null or usage_limit > 0');

        $coupons = $this->create('coupons', function (Blueprint $table) use ($promotions, $batches): void {
            $this->uuidPrimary($table);
            $table->uuid('promotion_id');
            $table->uuid('batch_id')->nullable();
            $table->string('code', 64);
            $table->string('status', 16)->default('active');
            $table->integer('usage_limit')->nullable();
            $table->integer('used')->default(0); // kept only while usage_limit is set (D27)
            $this->timestampsTz($table);
            $this->foreign($table, 'promotion_id')->references('id')->on($promotions)->restrictOnDelete();
            $this->foreign($table, 'batch_id')->references('id')->on($batches)->restrictOnDelete();
            $this->unique($table, 'code');
            $this->index($table, 'promotion_id');
            $this->index($table, 'batch_id');
        });

        // PRM-03: the canonical form is the last line of defence, whatever wrote the row.
        $this->check($coupons, 'code_canonical', "code ~ '^[A-Z0-9][A-Z0-9_-]{0,63}$'");
        $this->check($coupons, 'status_valid', "status in ('active', 'disabled')");
        $this->check($coupons, 'usage_limit_positive', 'usage_limit is null or usage_limit > 0');
        $this->check($coupons, 'used_non_negative', 'used >= 0');
    }

    public function down(): void
    {
        $this->drop('coupons');
        $this->drop('coupon_batches');
    }
};
