<?php

declare(strict_types=1);

use Packman\Schema\Laravel\PackmanMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends PackmanMigration
{
    public function up(): void
    {
        // `orders` belongs to the sales package, not to catalog.
        $this->create('orders', function (Blueprint $table): void {
            $this->uuidPrimary($table);
        });
    }

    public function down(): void
    {
        $this->drop('orders');
    }
};
