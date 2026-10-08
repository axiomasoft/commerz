<?php

declare(strict_types=1);

use Commerz\Schema\Internal\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Плагин commerz-coupons. FK на cmz_promotions допустим: вниз по Composer-зависимости (coupons → promotions).
return new class extends CommerzMigration
{
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        [$coupons, $batches, $redemptions, $promotions] = [
            $this->t('coupons'), $this->t('coupon_batches'), $this->t('coupon_redemptions'), $this->t('promotions'),
        ];

        $schema->create($batches, function (Blueprint $table) use ($batches, $promotions): void {
            $table->uuid('id');
            $table->uuid('promotion_id');
            $table->string('prefix', 16)->default('');
            $table->smallInteger('length');
            $table->integer('quantity');
            $table->integer('generated')->default(0);
            $table->timestampTz('created_at')->useCurrent();

            $table->primary('id');
            $table->foreign('promotion_id', "{$batches}_promotion_id_fk")->references('id')->on($promotions);
        });
        $this->namePrimaryKey($batches);
        $this->check($batches, 'length', 'length BETWEEN 6 AND 32');

        $schema->create($coupons, function (Blueprint $table) use ($coupons, $batches, $promotions): void {
            $table->uuid('id');
            $table->uuid('promotion_id');
            $table->uuid('batch_id')->nullable();
            $table->string('code', 64);
            $table->string('customer_ref', 128)->nullable();      // персональный код
            $table->integer('usage_limit')->nullable();
            $table->integer('usage_count')->default(0);
            $table->integer('per_customer_limit')->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->string('status', 16);
            $table->timestampTz('created_at')->useCurrent();

            $table->primary('id');
            $table->unique('code', "{$coupons}_code_uq");
            $table->index('promotion_id', "{$coupons}_promotion_id_ix");
            $table->foreign('promotion_id', "{$coupons}_promotion_id_fk")->references('id')->on($promotions);
            $table->foreign('batch_id', "{$coupons}_batch_id_fk")->references('id')->on($batches);
        });
        $this->namePrimaryKey($coupons);
        $this->check($coupons, 'code', "code ~ '^[A-Z0-9]{3,64}$'");
        $this->check($coupons, 'usage', 'usage_limit IS NULL OR usage_count <= usage_limit');
        $this->check($coupons, 'status', "status IN ('active','disabled')");

        $schema->create($redemptions, function (Blueprint $table) use ($redemptions, $coupons): void {
            $table->uuid('id');
            $table->uuid('coupon_id');
            $table->uuid('order_id');
            $table->string('customer_ref', 128)->nullable();
            $table->string('state', 16);
            $table->timestampTz('redeemed_at');
            $table->timestampTz('reverted_at')->nullable();

            $table->primary('id');
            $table->unique(['coupon_id', 'order_id'], "{$redemptions}_coupon_id_order_id_uq");
            $table->foreign('coupon_id', "{$redemptions}_coupon_id_fk")->references('id')->on($coupons);
        });
        $this->namePrimaryKey($redemptions);
        $this->check($redemptions, 'state', "state IN ('redeemed','reverted')");
        DB::connection($this->getConnection())->statement(
            "CREATE INDEX {$redemptions}_coupon_id_customer_ref_ix ON {$redemptions} (coupon_id, customer_ref) WHERE state = 'redeemed'"
        );
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        foreach (['coupon_redemptions', 'coupons', 'coupon_batches'] as $logical) {
            $schema->dropIfExists($this->t($logical));
        }
    }
};
