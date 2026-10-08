<?php

declare(strict_types=1);

use Commerz\Schema\Internal\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Пакет commerz-inventory. Нет FK на каталог (inventory не зависит от catalog — CoreX DATA-004).
return new class extends CommerzMigration
{
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        [$levels, $movements, $reservations, $items, $locations] = [
            $this->t('stock_levels'), $this->t('stock_movements'), $this->t('stock_reservations'),
            $this->t('stock_items'), $this->t('stock_locations'),
        ];

        $schema->create($levels, function (Blueprint $table) use ($levels, $items, $locations): void {
            $table->uuid('id');                                   // UUIDv7 генерирует приложение
            $table->uuid('stock_item_id');
            $table->uuid('location_id');
            $table->bigInteger('on_hand')->default(0);            // целые базовые единицы номенклатуры
            $table->bigInteger('reserved')->default(0);
            $table->bigInteger('safety_stock')->default(0);
            $table->boolean('allows_negative')->default(false);
            $table->bigInteger('version')->default(0);
            $table->timestampTz('updated_at')->useCurrent();

            $table->primary('id');
            $table->unique(['stock_item_id', 'location_id'], "{$levels}_stock_item_id_location_id_uq");
            $table->foreign('stock_item_id', "{$levels}_stock_item_id_fk")->references('id')->on($items);
            $table->foreign('location_id', "{$levels}_location_id_fk")->references('id')->on($locations);
        });
        $this->namePrimaryKey($levels);
        $this->check($levels, 'reserved', 'reserved >= 0');
        $this->check($levels, 'safety_stock', 'safety_stock >= 0');
        $this->check($levels, 'on_hand', 'allows_negative OR on_hand >= 0');

        $schema->create($movements, function (Blueprint $table) use ($movements, $levels): void {
            $table->bigIncrements('id');                          // журнал: bigint identity, порядок вставки
            $table->uuid('stock_level_id');
            $table->uuid('stock_item_id');                        // денормализация для отчётов без JOIN
            $table->uuid('location_id');
            $table->string('kind', 24);
            $table->bigInteger('delta');
            $table->bigInteger('balance_after');
            $table->string('source_type', 32);
            $table->string('source_id', 64);
            $table->string('reason', 255)->nullable();
            $table->string('actor_ref', 128)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at')->useCurrent();

            $table->unique(['source_type', 'source_id', 'stock_level_id', 'kind'], "{$movements}_source_uq");
            $table->index(['stock_level_id', 'id'], "{$movements}_stock_level_id_id_ix");
            $table->foreign('stock_level_id', "{$movements}_stock_level_id_fk")->references('id')->on($levels);
        });
        $this->namePrimaryKey($movements);
        $this->check($movements, 'delta', 'delta <> 0');
        $this->check($movements, 'kind', "kind IN ('receipt','shipment','adjustment','count','transfer_out','transfer_in','return_restock','correction')");
        DB::connection($this->getConnection())->statement("CREATE INDEX {$movements}_recorded_at_ix ON {$movements} USING brin (recorded_at)");

        $schema->create($reservations, function (Blueprint $table) use ($reservations, $levels): void {
            $table->uuid('id');
            $table->uuid('stock_level_id');
            $table->string('demand_type', 16);
            $table->string('demand_id', 64);
            $table->string('line_ref', 64);
            $table->bigInteger('quantity');
            $table->bigInteger('consumed')->default(0);
            $table->string('state', 16);
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->primary('id');
            // естественный ключ = идемпотентность резерва
            $table->unique(['demand_type', 'demand_id', 'line_ref', 'stock_level_id'], "{$reservations}_demand_uq");
            $table->foreign('stock_level_id', "{$reservations}_stock_level_id_fk")->references('id')->on($levels);
        });
        $this->namePrimaryKey($reservations);
        $this->check($reservations, 'quantity', 'quantity > 0 AND consumed >= 0 AND consumed <= quantity');
        $this->check($reservations, 'state', "state IN ('active','consumed','released','expired')");
        $this->check($reservations, 'demand_type', "demand_type IN ('order','cart','transfer','manual')");
        DB::connection($this->getConnection())->statement(
            "CREATE INDEX {$reservations}_expiry_ix ON {$reservations} (stock_level_id, expires_at) WHERE state = 'active'"
        );
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists($this->t('stock_reservations'));
        $schema->dropIfExists($this->t('stock_movements'));
        $schema->dropIfExists($this->t('stock_levels'));
    }
};
