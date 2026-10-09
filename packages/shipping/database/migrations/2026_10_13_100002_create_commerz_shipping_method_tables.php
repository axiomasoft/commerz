<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends CommerzMigration
{
    private const string CODE = "'^[a-z0-9][a-z0-9_.-]{0,63}$'";

    public function up(): void
    {
        $zones = $this->table('shipping_zones');

        $methods = $this->create('shipping_methods', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->string('code', 64);
            $table->jsonb('name'); // LocalizedText::$texts
            $table->string('carrier', 64);
            $table->string('status', 16)->default('active');
            $table->integer('position')->default(0);
            $this->timestampsTz($table);
            $this->unique($table, 'code');
        });

        $this->check($methods, 'code_valid', 'code ~ '.self::CODE);
        $this->check($methods, 'carrier_valid', 'carrier ~ '.self::CODE);
        $this->check($methods, 'status_valid', "status in ('active', 'disabled', 'archived')");
        $this->check($methods, 'name_object', "jsonb_typeof(name) = 'object'");

        $this->create('shipping_method_channels', function (Blueprint $table) use ($methods): void {
            $table->uuid('shipping_method_id');
            $table->uuid('channel_id'); // a core table: no foreign key
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->primary(['shipping_method_id', 'channel_id']);
            $this->foreign($table, 'shipping_method_id')->references('id')->on($methods)->cascadeOnDelete();
            $this->index($table, 'channel_id');
        });

        $rates = $this->create('shipping_rates', function (Blueprint $table) use ($methods, $zones): void {
            $this->uuidPrimary($table);
            $table->uuid('shipping_method_id');
            $table->uuid('shipping_zone_id');
            // Declared by hand: `money()` would make the currency nullable, a rate always has one.
            $table->char('currency', 3);
            $table->string('rate_key', 64);
            $table->integer('rate_version');
            $table->jsonb('params');
            $table->bigInteger('free_above_minor')->nullable();
            $table->boolean('tax_included');
            $table->integer('eta_days')->nullable();
            $this->timestampsTz($table);
            $this->foreign($table, 'shipping_method_id')->references('id')->on($methods)->cascadeOnDelete();
            $this->foreign($table, 'shipping_zone_id')->references('id')->on($zones);
            $this->unique($table, ['shipping_method_id', 'shipping_zone_id', 'currency']);
            $this->index($table, 'shipping_zone_id');
        });

        $this->check($rates, 'currency_valid', "currency ~ '^[A-Z]{3}$'");
        $this->check($rates, 'rate_version_positive', 'rate_version >= 1');
        $this->check($rates, 'free_above_non_negative', 'free_above_minor is null or free_above_minor >= 0');
        $this->check($rates, 'eta_days_valid', 'eta_days is null or eta_days between 0 and 365');
        $this->check($rates, 'params_object', "jsonb_typeof(params) = 'object'");
    }

    public function down(): void
    {
        $this->drop('shipping_rates');
        $this->drop('shipping_method_channels');
        $this->drop('shipping_methods');
    }
};
