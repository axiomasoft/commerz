<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        $bookings = $this->create('shipping_bookings', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->string('reference', 191);
            $table->string('carrier', 64);
            $table->string('method_code', 64);
            $table->char('fingerprint', 64);
            $table->string('state', 16);
            $table->integer('attempts');
            $table->timestampTz('claimed_at', 6);
            $table->string('tracking', 191)->nullable();
            $table->string('label_ref', 191)->nullable();
            $table->jsonb('result')->nullable(); // BookedShipment::$meta
            $table->string('error_code', 128)->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('booked_at', 6)->nullable();
            $this->timestampsTz($table);
            $this->unique($table, 'reference');
            $this->index($table, 'state');
        });

        $this->check($bookings, 'state_valid', "state in ('pending', 'booked', 'rejected', 'unknown')");
        $this->check($bookings, 'attempts_positive', 'attempts >= 1');
        $this->check($bookings, 'booked_has_tracking', "state <> 'booked' or (tracking is not null and booked_at is not null)");
    }

    public function down(): void
    {
        $this->drop('shipping_bookings');
    }
};
