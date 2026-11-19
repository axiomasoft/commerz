<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;
use Packman\Schema\Data\Compiler\ConstraintNames;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $classes = $this->create('tax_classes', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->string('code', 64);
            $table->string('name', 255);
            $this->timestampsTz($table);
            $this->unique($table, 'code');
        });

        $zones = $this->create('tax_zones', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->string('code', 64);
            $table->string('name', 255);
            $this->timestampsTz($table);
            $this->unique($table, 'code');
        });

        $members = $this->create('tax_zone_members', function (Blueprint $table) use ($zones): void {
            $this->identityPrimary($table);
            $table->uuid('tax_zone_id');
            $table->char('country', 2);
            $table->string('region', 6)->nullable();
            $this->foreign($table, 'tax_zone_id')->references('id')->on($zones)->cascadeOnDelete();
            $this->index($table, 'tax_zone_id');
        });

        $this->check($members, 'country_format', "country ~ '^[A-Z]{2}\$'");

        // TAX-01: a (country, region) pair, a missing region included, belongs to one zone only.
        $columns = ['country', 'region'];
        $unique = ConstraintNames::unique($members, $columns);
        $this->db()->statement("alter table \"{$members}\" add constraint \"{$unique}\" unique nulls not distinct (".implode(', ', $columns).')');

        $rates = $this->create('tax_rates', function (Blueprint $table) use ($classes, $zones): void {
            $this->uuidPrimary($table);
            $table->uuid('tax_class_id');
            $table->uuid('tax_zone_id');
            $table->string('code', 32)->default('vat');
            $table->integer('rate_bp');
            $table->timestampTz('valid_from', 6)->nullable();
            $table->timestampTz('valid_until', 6)->nullable();
            $this->timestampsTz($table);
            $this->foreign($table, 'tax_class_id')->references('id')->on($classes)->restrictOnDelete();
            $this->foreign($table, 'tax_zone_id')->references('id')->on($zones)->restrictOnDelete();
            $this->index($table, ['tax_zone_id', 'tax_class_id']);
        });

        $this->check($rates, 'rate_bp_range', 'rate_bp between 0 and 10000');
        $this->check($rates, 'window_ordered', 'valid_from is null or valid_until is null or valid_from < valid_until');

        $this->exclude(
            $rates,
            'window',
            'tax_class_id with =, tax_zone_id with =, code with =, tstzrange(valid_from, valid_until, \'[)\') with &&',
        );
    }

    public function down(): void
    {
        $this->drop('tax_rates');
        $this->drop('tax_zone_members');
        $this->drop('tax_zones');
        $this->drop('tax_classes');
    }
};
