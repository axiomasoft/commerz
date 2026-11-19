<?php

declare(strict_types=1);

use Commerz\Schema\CommerzMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends CommerzMigration
{
    public function up(): void
    {
        $this->schema()->table($this->table('fulfillments'), static function (Blueprint $table): void {
            $table->boolean('booking_requested')->default(false);
        });
    }

    public function down(): void
    {
        $this->schema()->table($this->table('fulfillments'), static function (Blueprint $table): void {
            $table->dropColumn('booking_requested');
        });
    }
};
