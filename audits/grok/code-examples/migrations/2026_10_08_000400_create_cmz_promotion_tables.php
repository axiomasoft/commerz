<?php

declare(strict_types=1);

use Commerz\Schema\Internal\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Пакет commerz-promotions. Условия и эффекты — строки (не JSON-дерево): unsupported по строке, config_version по строке.
return new class extends CommerzMigration
{
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        [$promotions, $conditions, $effects, $usages] = [
            $this->t('promotions'), $this->t('promotion_conditions'), $this->t('promotion_effects'), $this->t('promotion_usages'),
        ];

        $schema->create($promotions, function (Blueprint $table) use ($promotions): void {
            $table->uuid('id');
            $table->uuid('store_id');
            $table->string('code', 64);
            $table->jsonb('name');                                // {"ru": "...", "en": "..."}
            $table->string('stage', 16);
            $table->string('stacking', 16);
            $table->integer('priority')->default(0);
            $table->string('activator', 64);                      // 'automatic' | 'coupon' | 'acme.referral'
            $table->string('status', 16);
            $table->string('status_reason', 255)->nullable();     // почему unsupported
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->integer('usage_limit')->nullable();
            $table->integer('usage_count')->default(0);
            $table->integer('per_customer_limit')->nullable();
            $table->char('currency', 3)->nullable();              // для денежного бюджета
            $table->bigInteger('budget_minor')->nullable();
            $table->bigInteger('spent_minor')->default(0);
            $table->integer('revision')->default(1);              // снимок в заказе: promotion:<id>@<revision>
            $table->timestampsTz();

            $table->primary('id');
            $table->unique(['store_id', 'code'], "{$promotions}_store_id_code_uq");
            $table->index(['store_id', 'stage', 'status'], "{$promotions}_store_id_stage_status_ix");
        });
        $this->namePrimaryKey($promotions);
        $this->check($promotions, 'stage', "stage IN ('catalog','cart','shipping')");
        $this->check($promotions, 'stacking', "stacking IN ('stackable','exclusive','stop_further')");
        $this->check($promotions, 'status', "status IN ('draft','active','paused','archived','unsupported')");
        $this->check($promotions, 'usage', 'usage_limit IS NULL OR usage_count <= usage_limit');
        $this->check($promotions, 'budget', 'budget_minor IS NULL OR (currency IS NOT NULL AND spent_minor <= budget_minor)');
        $this->check($promotions, 'window', 'ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at');

        foreach ([$conditions, $effects] as $rules) {
            $schema->create($rules, function (Blueprint $table) use ($rules, $promotions): void {
                $table->uuid('id');
                $table->uuid('promotion_id');
                $table->string('type', 64);                       // ключ плагина, не FQCN
                $table->jsonb('config');
                $table->smallInteger('config_version');
                $table->smallInteger('position');

                $table->primary('id');
                $table->unique(['promotion_id', 'position'], "{$rules}_promotion_id_position_uq");
                $table->foreign('promotion_id', "{$rules}_promotion_id_fk")->references('id')->on($promotions)->cascadeOnDelete();
            });
            $this->namePrimaryKey($rules);
        }

        $schema->create($usages, function (Blueprint $table) use ($usages, $promotions): void {
            $table->uuid('id');
            $table->uuid('promotion_id');
            $table->uuid('order_id');                             // FK нет: sales зависит от promotions, не наоборот
            $table->string('customer_ref', 128)->nullable();
            $table->bigInteger('discount_minor');
            $table->string('state', 16);
            $table->timestampTz('used_at');
            $table->timestampTz('released_at')->nullable();

            $table->primary('id');
            $table->unique(['promotion_id', 'order_id'], "{$usages}_promotion_id_order_id_uq");
            $table->foreign('promotion_id', "{$usages}_promotion_id_fk")->references('id')->on($promotions);
        });
        $this->namePrimaryKey($usages);
        $this->check($usages, 'state', "state IN ('used','released')");
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        foreach (['promotion_usages', 'promotion_effects', 'promotion_conditions', 'promotions'] as $logical) {
            $schema->dropIfExists($this->t($logical));
        }
    }
};
