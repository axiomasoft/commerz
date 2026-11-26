<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;
use Packman\Schema\Data\Compiler\ConstraintNames;

return new class extends CommerzMigration
{
    private const string CODE = "'^[a-z0-9][a-z0-9_.-]{0,63}$'";

    private const string POINT_CODE = "'^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,190}$'";

    public function up(): void
    {
        $methods = $this->table('shipping_methods');

        $this->schema()->table($methods, static function (Blueprint $table): void {
            $table->string('delivery_mode', 32)->default('shipping');
        });

        $this->check($methods, 'delivery_mode_valid', "delivery_mode in ('shipping', 'pickup_point', 'local_pickup')");

        $points = $this->create('shipping_pickup_points', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->string('provider', 64);
            $table->string('code', 191);
            $table->jsonb('name');
            $table->string('mode', 32)->default('pickup_point');
            $table->char('country', 2);
            $table->string('region', 6)->nullable();
            $table->string('city', 191);
            $table->string('city_normalized', 191);
            $table->string('postal_code', 32)->nullable();
            $table->jsonb('address_lines')->default('[]');
            $table->string('phone', 64)->nullable();
            $table->integer('latitude_microdeg')->nullable();
            $table->integer('longitude_microdeg')->nullable();
            $table->boolean('is_locker')->default(false);
            $table->integer('max_weight_grams')->nullable();
            $table->integer('max_length_mm')->nullable();
            $table->integer('max_width_mm')->nullable();
            $table->integer('max_height_mm')->nullable();
            $table->text('opening_hours')->nullable();
            $table->string('status', 16)->default('active');
            $table->jsonb('meta')->default('{}');
            $this->timestampsTz($table);
            $this->unique($table, ['provider', 'code']);
            $this->index($table, ['country', 'city_normalized', 'status']);
            $this->index($table, ['country', 'status']);
        });

        $this->check($points, 'provider_valid', 'provider ~ '.self::CODE);
        $this->check($points, 'code_valid', 'code ~ '.self::POINT_CODE);
        $this->check($points, 'name_object', "jsonb_typeof(name) = 'object'");
        $this->check($points, 'mode_valid', "mode in ('pickup_point', 'local_pickup')");
        $this->check($points, 'country_valid', "country ~ '^[A-Z]{2}$'");
        $this->check($points, 'address_lines_array', "jsonb_typeof(address_lines) = 'array'");
        $this->check($points, 'coords_paired', '(latitude_microdeg is null) = (longitude_microdeg is null)');
        $this->check($points, 'lat_valid', 'latitude_microdeg is null or latitude_microdeg between -90000000 and 90000000');
        $this->check($points, 'lon_valid', 'longitude_microdeg is null or longitude_microdeg between -180000000 and 180000000');
        $this->check(
            $points,
            'limits_positive',
            '(max_weight_grams is null or max_weight_grams >= 1) and (max_length_mm is null or max_length_mm >= 1) and (max_width_mm is null or max_width_mm >= 1) and (max_height_mm is null or max_height_mm >= 1)',
        );
        $this->check($points, 'status_valid', "status in ('active', 'disabled', 'archived')");
        $this->check($points, 'meta_object', "jsonb_typeof(meta) = 'object'");

        $bookings = $this->table('shipping_bookings');

        $parcels = $this->create('shipping_booking_parcels', function (Blueprint $table) use ($bookings): void {
            $this->uuidPrimary($table);
            $table->uuid('shipping_booking_id');
            $table->integer('sequence');
            $table->string('tracking', 191);
            $table->string('label_ref', 191)->nullable();
            $table->integer('weight_grams');
            $table->char('currency', 3);
            $table->bigInteger('value_minor');
            $table->integer('length_mm')->nullable();
            $table->integer('width_mm')->nullable();
            $table->integer('height_mm')->nullable();
            $table->jsonb('meta')->default('{}');
            $this->timestampsTz($table);
            $this->foreign($table, 'shipping_booking_id')->references('id')->on($bookings)->cascadeOnDelete();
            $this->unique($table, ['shipping_booking_id', 'sequence']);
            $this->index($table, 'tracking');
        });

        $this->check($parcels, 'sequence_positive', 'sequence >= 1');
        $this->check($parcels, 'tracking_non_empty', 'length(tracking) >= 1');
        $this->check($parcels, 'weight_non_negative', 'weight_grams >= 0');
        $this->check($parcels, 'currency_valid', "currency ~ '^[A-Z]{3}$'");
        $this->check($parcels, 'value_non_negative', 'value_minor >= 0');
        $this->check(
            $parcels,
            'dims_positive',
            '(length_mm is null or length_mm >= 1) and (width_mm is null or width_mm >= 1) and (height_mm is null or height_mm >= 1)',
        );
        $this->check($parcels, 'meta_object', "jsonb_typeof(meta) = 'object'");
    }

    public function down(): void
    {
        $this->drop('shipping_booking_parcels');
        $this->drop('shipping_pickup_points');

        $methods = $this->table('shipping_methods');
        $checkName = ConstraintNames::check($methods, 'delivery_mode_valid');
        $this->db()->statement("alter table \"{$methods}\" drop constraint if exists \"{$checkName}\"");

        $this->schema()->table($methods, static function (Blueprint $table): void {
            $table->dropColumn('delivery_mode');
        });
    }
};
