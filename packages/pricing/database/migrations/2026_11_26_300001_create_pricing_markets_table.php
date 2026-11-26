<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $priceLists = $this->table('price_lists');

        $markets = $this->create('markets', function (Blueprint $table) use ($priceLists): void {
            $this->uuidPrimary($table);
            $table->string('code', 64);
            $table->string('name', 255);
            $table->uuid('channel_id'); // core table: no foreign key
            $table->char('default_currency', 3);
            $table->jsonb('allowed_currencies');
            $table->boolean('tax_included')->default(false);
            $table->uuid('default_price_list_id')->nullable();
            $table->string('status', 16)->default('active');
            $this->timestampsTz($table);
            $this->unique($table, 'code');
            $this->index($table, ['channel_id', 'status']);
            $this->foreign($table, 'default_price_list_id')->references('id')->on($priceLists)->restrictOnDelete();
        });

        $this->check($markets, 'status_valid', "status in ('active', 'archived')");
        $this->check($markets, 'currencies_array', "jsonb_typeof(allowed_currencies) = 'array' and jsonb_array_length(allowed_currencies) >= 1");

        $countries = $this->create('market_countries', function (Blueprint $table) use ($markets): void {
            $table->uuid('market_id');
            $table->uuid('channel_id');
            $table->char('country', 2);
            $table->primary(['market_id', 'country']);
            $this->foreign($table, 'market_id')->references('id')->on($markets)->cascadeOnDelete();
            $this->unique($table, ['channel_id', 'country']);
            $this->index($table, 'country');
        });

        $this->check($countries, 'country_format', "country ~ '^[A-Z]{2}\$'");
    }

    public function down(): void
    {
        $this->drop('market_countries');
        $this->drop('markets');
    }
};
