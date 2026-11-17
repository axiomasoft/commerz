<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
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
