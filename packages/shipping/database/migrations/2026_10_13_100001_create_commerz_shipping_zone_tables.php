<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Commerz\Schema\ConstraintNames;
use Illuminate\Database\Schema\Blueprint;

return new class extends CommerzMigration
{
    private const string CODE = "'^[a-z0-9][a-z0-9_.-]{0,63}$'";

    public function up(): void
    {
        $zones = $this->create('shipping_zones', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->string('code', 64);
            $table->string('name', 191);
            $table->string('status', 16)->default('active');
            $this->timestampsTz($table);
            $this->unique($table, 'code');
        });

        $this->check($zones, 'code_valid', 'code ~ '.self::CODE);
        $this->check($zones, 'status_valid', "status in ('active', 'archived')");

        $members = $this->create('shipping_zone_members', function (Blueprint $table) use ($zones): void {
            $this->identityPrimary($table);
            $table->uuid('shipping_zone_id');
            $table->char('country', 2);
            $table->string('region', 6)->nullable();
            $table->string('postal_prefix', 10)->nullable();
            $this->foreign($table, 'shipping_zone_id')->references('id')->on($zones)->cascadeOnDelete();
            $this->index($table, 'country');
        });

        $this->check($members, 'country_valid', "country ~ '^[A-Z]{2}$'");
        $this->check($members, 'region_valid', "region is null or (region ~ '^[A-Z]{2}-[A-Z0-9]{1,3}$' and left(region, 2) = country)");
        $this->check($members, 'postal_prefix_valid', "postal_prefix is null or postal_prefix ~ '^[A-Z0-9]{1,10}$'");
        $this->check($members, 'region_xor_postal', 'region is null or postal_prefix is null');

        // A member with NULL region or prefix must not repeat: a plain UNIQUE treats every NULL as distinct.
        $unique = ConstraintNames::unique($members, ['shipping_zone_id', 'country', 'region', 'postal_prefix']);
        $this->db()->statement("create unique index \"{$unique}\" on \"{$members}\" (shipping_zone_id, country, region, postal_prefix) nulls not distinct");
    }

    public function down(): void
    {
        $this->drop('shipping_zone_members');
        $this->drop('shipping_zones');
    }
};
