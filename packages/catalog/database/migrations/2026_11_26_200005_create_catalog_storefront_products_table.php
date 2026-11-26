<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $products = $this->table('products');

        $storefront = $this->create('storefront_products', function (Blueprint $table) use ($products): void {
            // No foreign key on channel_id: channels belong to the core package.
            $table->uuid('channel_id');
            $table->string('locale', 32);
            $table->uuid('product_id');
            $table->jsonb('snapshot');
            $table->bigInteger('price_min_minor')->nullable();
            $table->bigInteger('price_max_minor')->nullable();
            $table->boolean('in_stock')->default(false);
            $table->timestampTz('updated_at', 6)->useCurrent();
            $table->primary(['channel_id', 'locale', 'product_id']);
            $this->index($table, 'product_id');
            $this->index($table, ['channel_id', 'locale', 'in_stock']);
            $this->index($table, ['channel_id', 'locale', 'price_min_minor']);
            $this->foreign($table, 'product_id')->references('id')->on($products)->cascadeOnDelete();
        });

        if ($this->db()->getDriverName() === 'pgsql') {
            $this->check($storefront, 'snapshot_object', "jsonb_typeof(snapshot) = 'object'");
            $this->check($storefront, 'locale_not_empty', 'length(btrim(locale)) > 0');
            $this->check(
                $storefront,
                'price_bounds_valid',
                '(price_min_minor is null and price_max_minor is null) or (price_min_minor is not null and price_max_minor is not null and price_min_minor >= 0 and price_max_minor >= price_min_minor)',
            );
        }
    }

    public function down(): void
    {
        $this->drop('storefront_products');
    }
};
