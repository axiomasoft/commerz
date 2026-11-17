<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        // Equality on scalar columns inside an EXCLUDE needs the extension. It is trusted since PostgreSQL 13 (CREATE on
        // the database is enough) and may be shared with other schemas, so down() leaves it.
        $this->db()->statement('create extension if not exists btree_gist');

        $lists = $this->create('price_lists', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->string('code', 64);
            $table->string('name', 255);
            $table->char('currency', 3);
            $table->integer('priority')->default(0);
            $table->boolean('tax_included')->nullable(); // null: the channel decides
            $table->string('status', 16)->default('active');
            $this->timestampsTz($table);
            $this->unique($table, 'code');
            $this->index($table, ['currency', 'status']);
        });

        $this->check($lists, 'status_valid', "status in ('active', 'archived')");

        $this->create('price_list_channels', function (Blueprint $table) use ($lists): void {
            $table->uuid('price_list_id');
            $table->uuid('channel_id'); // a core table: no foreign key
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->primary(['price_list_id', 'channel_id']);
            $this->foreign($table, 'price_list_id')->references('id')->on($lists)->cascadeOnDelete();
            $this->index($table, 'channel_id');
        });

        $prices = $this->create('prices', function (Blueprint $table) use ($lists): void {
            $this->uuidPrimary($table);
            $table->uuid('price_list_id');
            $this->ref($table, 'sellable');
            $table->string('customer_group', 64)->nullable();
            $table->bigInteger('min_quantity')->default(1);
            $this->money($table, 'amount');
            $this->money($table, 'compare_at', nullable: true);
            $table->timestampTz('valid_from', 6)->nullable();
            $table->timestampTz('valid_until', 6)->nullable();
            $this->timestampsTz($table);
            $this->foreign($table, 'price_list_id')->references('id')->on($lists)->restrictOnDelete();
            $this->index($table, ['sellable_ref', 'price_list_id']);
        });

        $this->check($prices, 'min_quantity_positive', 'min_quantity >= 1');
        $this->check($prices, 'amount_non_negative', 'amount_minor >= 0');
        $this->check($prices, 'compare_at_above_amount', 'compare_at_minor is null or compare_at_minor > amount_minor');
        $this->check($prices, 'window_ordered', 'valid_from is null or valid_until is null or valid_from < valid_until');

        // PRC-01: the windows of one price (list, sellable, group, quantity step) never overlap; null group is its own group.
        $this->exclude(
            $prices,
            'window',
            "price_list_id with =, sellable_ref with =, (coalesce(customer_group, '')) with =, min_quantity with =, tstzrange(valid_from, valid_until, '[)') with &&",
        );
    }

    public function down(): void
    {
        $this->drop('prices');
        $this->drop('price_list_channels');
        $this->drop('price_lists');
    }
};
