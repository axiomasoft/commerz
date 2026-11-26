<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;
use Packman\Schema\Support\ConstraintNames;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $items = $this->table('stock_items');

        $this->schema()->table($items, static function (Blueprint $table): void {
            $table->string('hs_code', 32)->nullable();
            $table->string('origin_country_code', 2)->nullable();
            $table->string('material', 191)->nullable();
            $table->string('customs_description', 500)->nullable();
        });

        $this->check($items, 'origin_country_valid', "origin_country_code is null or origin_country_code ~ '^[A-Z]{2}$'");
    }

    public function down(): void
    {
        $items = $this->table('stock_items');
        $constraint = ConstraintNames::check($items, 'origin_country_valid');
        $this->db()->statement("alter table \"{$items}\" drop constraint if exists \"{$constraint}\"");

        $this->schema()->table($items, static function (Blueprint $table): void {
            $table->dropColumn(['hs_code', 'origin_country_code', 'material', 'customs_description']);
        });
    }
};
