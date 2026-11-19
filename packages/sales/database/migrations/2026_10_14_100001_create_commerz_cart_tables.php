<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $carts = $this->create('carts', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->string('kind', 16)->default('cart');
            $table->string('state', 16)->default('open');
            $table->uuid('channel_id'); // a core table: no foreign key
            // Declared by hand: `money()` would make the currency nullable, a cart always has one.
            $table->char('currency', 3);
            $table->text('locale'); // the core bounds a locale tag by its grammar only
            $this->ref($table, 'customer', nullable: true);
            $table->jsonb('customer_groups')->default('[]');
            $table->jsonb('codes')->default('[]');
            $table->jsonb('shipping_address')->nullable();
            $table->jsonb('billing_address')->nullable();
            $table->string('delivery_method', 64)->nullable();
            $this->ref($table, 'created_by', nullable: true);
            $table->integer('version')->default(1);
            $table->timestampTz('converted_at', 6)->nullable();
            $this->timestampsTz($table);
            $this->index($table, ['state', 'kind', 'created_at']);
        });

        $this->check($carts, 'kind_valid', "kind in ('cart', 'draft')");
        $this->check($carts, 'state_valid', "state in ('open', 'converted')");
        $this->check($carts, 'version_positive', 'version >= 1');
        $this->check($carts, 'currency_valid', "currency ~ '^[A-Z]{3}$'");
        $this->check($carts, 'customer_groups_array', "jsonb_typeof(customer_groups) = 'array'");
        $this->check($carts, 'codes_array', "jsonb_typeof(codes) = 'array'");
        $this->check($carts, 'converted_at_matches_state', "(state = 'converted') = (converted_at is not null)");

        $lines = $this->create('cart_lines', function (Blueprint $table) use ($carts): void {
            $this->uuidPrimary($table);
            $table->uuid('cart_id');
            $this->ref($table, 'sellable');
            $table->integer('quantity');
            $table->integer('position');
            $this->timestampsTz($table);
            $this->foreign($table, 'cart_id')->references('id')->on($carts)->cascadeOnDelete();
            $this->unique($table, ['cart_id', 'sellable_ref']);
            $this->index($table, ['cart_id', 'position']);
        });

        $this->check($lines, 'quantity_positive', 'quantity >= 1');
    }

    public function down(): void
    {
        $this->drop('cart_lines');
        $this->drop('carts');
    }
};
