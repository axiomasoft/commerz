<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        $stores = $this->table('stores');

        $table = $this->create('channels', function (Blueprint $table) use ($stores): void {
            $this->uuidPrimary($table);
            $table->uuid('store_id');
            $table->string('code', 64);
            $table->string('name', 191);
            $table->jsonb('currencies');
            $table->char('default_currency', 3);
            $table->jsonb('locales');
            $table->string('default_locale', 35);
            $table->boolean('prices_include_tax')->default(false);
            $table->string('status', 16)->default('active');
            $this->timestampsTz($table);
            $this->unique($table, 'code');
            $this->index($table, 'store_id');
            $this->foreign($table, 'store_id')->references('id')->on($stores)->restrictOnDelete();
        });

        $this->check($table, 'status_valid', "status in ('active', 'archived')");
        $this->check($table, 'currencies_list', "jsonb_typeof(currencies) = 'array' and jsonb_array_length(currencies) > 0");
        $this->check($table, 'locales_list', "jsonb_typeof(locales) = 'array' and jsonb_array_length(locales) > 0");
        $this->check($table, 'default_currency_listed', 'currencies @> to_jsonb(default_currency)');
        $this->check($table, 'default_locale_listed', 'locales @> to_jsonb(default_locale)');
    }

    public function down(): void
    {
        $this->drop('channels');
    }
};
